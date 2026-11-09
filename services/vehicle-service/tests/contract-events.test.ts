import { randomUUID } from "node:crypto";
import { afterAll, describe, expect, it } from "vitest";
import { prisma } from "../lib/prisma";
import { handleEvent, PermanentError } from "../lib/contract-events";

const V3 = "a1154300-bea1-4072-b750-2b00cf1f0bcd";
const usedIds: string[] = [];

function message(type: string, data: Record<string, unknown>, eventId = randomUUID()) {
  usedIds.push(eventId);
  return Buffer.from(JSON.stringify({ eventId, type, occurredAt: new Date().toISOString(), data }));
}

const v3 = () => prisma.vehicle.findUniqueOrThrow({ where: { id: V3 } });

afterAll(async () => {
  // Remet les données de démo dans leur état initial
  await prisma.vehicle.update({ where: { id: V3 }, data: { status: "AVAILABLE", mileage: 42980 } });
  await prisma.processedEvent.deleteMany({ where: { eventId: { in: usedIds } } });
  await prisma.$disconnect();
});

describe("Consumer vehicle.contract_events", () => {
  it("VehicleHandedOver -> RENTED + kilométrage", async () => {
    await handleEvent(message("VehicleHandedOver", { vehicleId: V3, mileage: 43000 }));
    expect(await v3()).toMatchObject({ status: "RENTED", mileage: 43000 });
  });

  it("VehicleReturned -> AVAILABLE + kilométrage", async () => {
    await handleEvent(message("VehicleReturned", { vehicleId: V3, mileage: 43500, fuelLevel: 0.5 }));
    expect(await v3()).toMatchObject({ status: "AVAILABLE", mileage: 43500 });
  });

  it("ignore un eventId déjà traité (idempotence)", async () => {
    const id = randomUUID();
    await handleEvent(message("VehicleHandedOver", { vehicleId: V3, mileage: 44000 }, id));
    // Même eventId avec un autre contenu : doit être ignoré
    await handleEvent(message("VehicleReturned", { vehicleId: V3, mileage: 99999 }, id));
    expect(await v3()).toMatchObject({ status: "RENTED", mileage: 44000 });
  });

  it("véhicule inconnu -> erreur définitive (DLQ)", async () => {
    const msg = message("VehicleReturned", { vehicleId: "00000000-0000-4000-8000-000000000000", mileage: 1 });
    await expect(handleEvent(msg)).rejects.toBeInstanceOf(PermanentError);
  });

  it("type inconnu -> erreur définitive (DLQ)", async () => {
    const msg = message("VehicleStolen", { vehicleId: V3, mileage: 1 });
    await expect(handleEvent(msg)).rejects.toBeInstanceOf(PermanentError);
  });

  it("JSON invalide -> erreur définitive (DLQ)", async () => {
    await expect(handleEvent(Buffer.from("pas du json"))).rejects.toBeInstanceOf(PermanentError);
  });
});