import { z } from "zod";
import { prisma } from "./prisma";
import { UUID_RE } from "./http";

// Erreur définitive : inutile de réessayer -> DLQ directement
export class PermanentError extends Error {}

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

export async function handleEvent(raw: Buffer) {
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

  await prisma.$transaction([
    prisma.vehicle.update({
      where: { id: data.vehicleId },
      data: { status, mileage: data.mileage },
    }),
    prisma.processedEvent.create({ data: { eventId, type } }),
  ]);

  console.log(`[consumer] ${type} : ${vehicle.plate} -> ${status}, ${data.mileage} km`);
}