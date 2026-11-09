import { z } from "zod";
import { UUID_RE } from "./http";   


export const categorySchema = z.object({
  name: z.string().trim().min(1).max(100),
  dailyRate: z.number().positive(),
  deposit: z.number().nonnegative(),
});

export const agencySchema = z.object({
  name: z.string().trim().min(1).max(100),
  city: z.string().trim().min(1).max(100),
  address: z.string().trim().min(1).max(255),
});

const uuid = z.string().regex(UUID_RE, "UUID attendu");
export const FUELS = ["GASOLINE", "DIESEL", "HYBRID", "ELECTRIC"] as const;
export const TRANSMISSIONS = ["MANUAL", "AUTOMATIC"] as const;
export const STATUSES = ["AVAILABLE", "RENTED", "MAINTENANCE", "OUT_OF_SERVICE"] as const;

export const vehicleSchema = z.object({
  plate: z.string().trim().min(1).max(20),
  brand: z.string().trim().min(1).max(50),
  model: z.string().trim().min(1).max(50),
  year: z.number().int().min(1990).max(new Date().getFullYear() + 1),
  fuel: z.enum(FUELS),
  transmission: z.enum(TRANSMISSIONS),
  seats: z.number().int().min(1).max(9),
  mileage: z.number().int().nonnegative(),
  status: z.enum(STATUSES).default("AVAILABLE"),
  categoryId: uuid,
  agencyId: uuid,
});

export const vehicleFilterSchema = z.object({
  categoryId: uuid.optional(),
  agencyId: uuid.optional(),
  fuel: z.enum(FUELS).optional(),
  transmission: z.enum(TRANSMISSIONS).optional(),
  status: z.enum(STATUSES).optional(),
  maxPrice: z.coerce.number().positive().optional(),
});

export const maintenanceSchema = z.object({
  type: z.string().trim().min(1).max(100),
  date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "Date attendue au format YYYY-MM-DD"),
  cost: z.number().nonnegative(),
});