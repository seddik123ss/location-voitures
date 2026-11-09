import amqp, { type ConsumeMessage } from "amqplib";
import { prisma } from "../lib/prisma";
import { handleEvent, PermanentError } from "../lib/contract-events";

const QUEUE = "vehicle.contract_events";
const DLQ = `${QUEUE}.dlq`;
const RABBITMQ_URL = process.env.RABBITMQ_URL ?? "amqp://guest:guest@localhost:5672";

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