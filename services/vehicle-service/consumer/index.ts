import amqp, { type ConsumeMessage } from "amqplib";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { UUID_RE } from "../lib/http";

const QUEUE = "vehicle.contract_events";
const DLQ = `${QUEUE}.dlq`;
const RABBITMQ_URL = process.env.RABBITMQ_URL ?? "amqp://guest:guest@localhost:5672";

// Erreur définitive : inutile de réessayer -> DLQ directement
class PermanentError extends Error {}

const envelopeSchema = z.object({
  eventId: z.string().regex(UUID_RE),
  type: z.string(),
  occurredAt: z.string(),
  data: z
    .object({
      vehicleId: z.string().regex(UUID_RE),
      mileage: z.number().int().nonnegative(),
    })
    .passthrough(),
});

const STATUS_BY_TYPE = {
  VehicleHandedOver: "RENTED",
  VehicleReturned: "AVAILABLE",
} as const;

async function handleEvent(raw: Buffer) {
  let json: unknown;
  try {
    json = JSON.parse(raw.toString("utf8"));
  } catch {
    throw new PermanentError("JSON invalide");
  }

  const parsed = envelopeSchema.safeParse(json);
  if (!parsed.success) {
    const why = parsed.error.issues.map((i) => `${i.path.map(String).join(".")} ${i.message}`).join("; ");
    throw new PermanentError(`Message invalide : ${why}`);
  }
  const { eventId, type, data } = parsed.data;

  const status = STATUS_BY_TYPE[type as keyof typeof STATUS_BY_TYPE];
  if (!status) throw new PermanentError(`Type inconnu : ${type}`);

  // Idempotence : un eventId déjà traité est ignoré
  if (await prisma.processedEvent.findUnique({ where: { eventId } })) {
    console.log(`[consumer] ${type} ${eventId} déjà traité -> ignoré`);
    return;
  }

  const vehicle = await prisma.vehicle.findUnique({ where: { id: data.vehicleId } });
  if (!vehicle) throw new PermanentError(`Véhicule inconnu : ${data.vehicleId}`);

  // Mise à jour du véhicule et mémorisation de l'eventId dans la même transaction
  await prisma.$transaction([
    prisma.vehicle.update({
      where: { id: data.vehicleId },
      data: { status, mileage: data.mileage },
    }),
    prisma.processedEvent.create({ data: { eventId, type } }),
  ]);

  console.log(`[consumer] ${type} : ${vehicle.plate} -> ${status}, ${data.mileage} km`);
}

async function main() {
  const conn = await amqp.connect(RABBITMQ_URL);
  const ch = await conn.createChannel();

  // Le consommateur déclare ses files (durables)
  await ch.assertQueue(QUEUE, { durable: true });
  await ch.assertQueue(DLQ, { durable: true });
  await ch.prefetch(1);

  const onMessage = async (msg: ConsumeMessage | null) => {
    if (!msg) return;
    try {
      await handleEvent(msg.content);
      ch.ack(msg);
    } catch (e) {
      const reason = e instanceof Error ? e.message : String(e);
      // Erreur définitive, ou déjà remis en file une fois -> DLQ
      if (e instanceof PermanentError || msg.fields.redelivered) {
        ch.sendToQueue(DLQ, msg.content, {
          persistent: true,
          contentType: "application/json",
          headers: { ...(msg.properties.headers ?? {}), "x-error": reason },
        });
        ch.ack(msg);
        console.error(`[consumer] -> ${DLQ} : ${reason}`);
      } else {
        console.warn(`[consumer] erreur, une remise en file : ${reason}`);
        ch.nack(msg, false, true);
      }
    }
  };

  await ch.consume(QUEUE, onMessage, { noAck: false });
  console.log(`[consumer] en écoute sur ${QUEUE} (prefetch 1, DLQ ${DLQ})`);

  const stop = async () => {
    console.log("[consumer] arrêt…");
    await ch.close().catch(() => {});
    await conn.close().catch(() => {});
    await prisma.$disconnect();
    process.exit(0);
  };
  process.once("SIGINT", stop);
  process.once("SIGTERM", stop);
}

main().catch(async (e) => {
  console.error("[consumer] démarrage impossible :", e instanceof Error ? e.message : e);
  await prisma.$disconnect();
  process.exit(1);
});