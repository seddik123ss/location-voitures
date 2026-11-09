import type { Agency, Category, Maintenance, Vehicle } from "@prisma/client";

export const toCategory = (c: Category) => ({
  id: c.id,
  name: c.name,
  dailyRate: c.dailyRate.toNumber(),
  deposit: c.deposit.toNumber(),
});

export const toAgency = (a: Agency) => ({
  id: a.id,
  name: a.name,
  city: a.city,
  address: a.address,
});

export const toVehicle = (v: Vehicle & { category: Category; agency: Agency }) => ({
  id: v.id,
  plate: v.plate,
  brand: v.brand,
  model: v.model,
  year: v.year,
  fuel: v.fuel,
  transmission: v.transmission,
  seats: v.seats,
  mileage: v.mileage,
  status: v.status,
  categoryId: v.categoryId,
  agencyId: v.agencyId,
  category: toCategory(v.category),
  agency: toAgency(v.agency),
});

export const toMaintenance = (m: Maintenance) => ({
  id: m.id,
  vehicleId: m.vehicleId,
  type: m.type,
  date: m.date.toISOString().slice(0, 10),
  cost: m.cost.toNumber(),
});