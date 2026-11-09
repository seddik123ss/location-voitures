import { PrismaClient, Fuel, Transmission, VehicleStatus } from "@prisma/client";

const prisma = new PrismaClient();

// UUID repris de docs/demo-ids.md — ne pas modifier
const AG = {
  tunis: "eb825ac4-855b-499b-b37a-b4c37bbd6b4e",
  sousse: "1deb1dea-ab54-436d-8ed7-c3322f782c88",
};

const CAT = {
  economique: "94236cd0-16fc-41e0-bfc2-c8b1d2c8b1fc",
  suv: "a6ec349d-0ff0-4cb1-bfb9-453dd8e2ee8c",
  luxe: "98d95d25-4112-4e52-8fbf-d2d908239b34",
};

const agencies = [
  { id: AG.tunis, name: "Agence Tunis", city: "Tunis", address: "Avenue Habib Bourguiba" },
  { id: AG.sousse, name: "Agence Sousse", city: "Sousse", address: "Boulevard du 14 Janvier" },
];

const categories = [
  { id: CAT.economique, name: "Économique", dailyRate: "90.000", deposit: "500.000" },
  { id: CAT.suv, name: "SUV", dailyRate: "160.000", deposit: "1000.000" },
  { id: CAT.luxe, name: "Luxe", dailyRate: "300.000", deposit: "2000.000" },
];

const vehicles = [
  { id: "ecdcc60f-ed6a-4007-8622-2b568338ef7a", plate: "123 TU 4501", brand: "Peugeot", model: "208", year: 2024, fuel: Fuel.GASOLINE, transmission: Transmission.MANUAL, mileage: 15000, status: VehicleStatus.AVAILABLE, categoryId: CAT.economique, agencyId: AG.tunis },
  { id: "9f03ab2d-97a5-4c08-a952-dc6e446370d1", plate: "124 TU 4502", brand: "Renault", model: "Clio", year: 2023, fuel: Fuel.DIESEL, transmission: Transmission.MANUAL, mileage: 38000, status: VehicleStatus.AVAILABLE, categoryId: CAT.economique, agencyId: AG.tunis },
  { id: "a1154300-bea1-4072-b750-2b00cf1f0bcd", plate: "125 TU 4503", brand: "Kia", model: "Sportage", year: 2024, fuel: Fuel.DIESEL, transmission: Transmission.AUTOMATIC, mileage: 42980, status: VehicleStatus.AVAILABLE, categoryId: CAT.suv, agencyId: AG.sousse },
  { id: "2657e174-f016-4f0c-9485-0c63141432e3", plate: "126 TU 4504", brand: "Hyundai", model: "Tucson", year: 2023, fuel: Fuel.GASOLINE, transmission: Transmission.AUTOMATIC, mileage: 27000, status: VehicleStatus.AVAILABLE, categoryId: CAT.suv, agencyId: AG.tunis },
  { id: "15073301-d2df-45b0-9055-6b34fe40c07e", plate: "127 TU 4505", brand: "Mercedes", model: "Classe C", year: 2024, fuel: Fuel.DIESEL, transmission: Transmission.AUTOMATIC, mileage: 12000, status: VehicleStatus.AVAILABLE, categoryId: CAT.luxe, agencyId: AG.tunis },
  { id: "4d23ae40-9d0d-47e0-a5bc-60dc9690242e", plate: "128 TU 4506", brand: "Volkswagen", model: "Polo", year: 2022, fuel: Fuel.GASOLINE, transmission: Transmission.MANUAL, mileage: 61000, status: VehicleStatus.MAINTENANCE, categoryId: CAT.economique, agencyId: AG.sousse },
];

async function main() {
  for (const a of agencies) {
    await prisma.agency.upsert({ where: { id: a.id }, update: a, create: a });
  }
  for (const c of categories) {
    await prisma.category.upsert({ where: { id: c.id }, update: c, create: c });
  }
  for (const v of vehicles) {
    const data = { ...v, seats: 5 };
    await prisma.vehicle.upsert({ where: { id: v.id }, update: data, create: data });
  }
  console.log(`Seed OK : ${agencies.length} agences, ${categories.length} catégories, ${vehicles.length} véhicules`);
}

main()
  .then(() => prisma.$disconnect())
  .catch(async (e) => {
    console.error(e);
    await prisma.$disconnect();
    process.exit(1);
  });