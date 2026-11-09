import type { Agency, Category } from "@prisma/client";

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