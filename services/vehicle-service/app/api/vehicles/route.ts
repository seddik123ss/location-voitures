import { prisma } from "@/lib/prisma";
import { handle, parsePagination, parseQuery, readJson } from "@/lib/api";
import { vehicleFilterSchema, vehicleSchema } from "@/lib/schemas";
import { toVehicle } from "@/lib/serializers";
import { ensureRefsExist } from "@/lib/vehicle-refs";
import { requireRoles, STAFF } from "@/lib/auth"; // ← AJOUT

export const dynamic = "force-dynamic";

export const GET = handle(async (req) => {
  const url = new URL(req.url);
  const { page, size, skip, take } = parsePagination(url);
  const f = parseQuery(url, vehicleFilterSchema);

  const where = {
    categoryId: f.categoryId,
    agencyId: f.agencyId,
    fuel: f.fuel,
    transmission: f.transmission,
    status: f.status,
    ...(f.maxPrice !== undefined && { category: { dailyRate: { lte: f.maxPrice } } }),
  };

  const [items, total] = await prisma.$transaction([
    prisma.vehicle.findMany({
      where,
      skip,
      take,
      orderBy: { brand: "asc" },
      include: { category: true, agency: true },
    }),
    prisma.vehicle.count({ where }),
  ]);

  return Response.json({ items: items.map(toVehicle), page, size, total });
});

export const POST = handle(async (req) => {
  await requireRoles(req, STAFF); // ← AJOUT
  const data = await readJson(req, vehicleSchema);
  await ensureRefsExist(data.categoryId, data.agencyId);
  const v = await prisma.vehicle.create({
    data,
    include: { category: true, agency: true },
  });
  return Response.json(toVehicle(v), {
    status: 201,
    headers: { Location: `/api/vehicles/${v.id}` },
  });
});