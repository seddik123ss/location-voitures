import { z } from "zod";

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