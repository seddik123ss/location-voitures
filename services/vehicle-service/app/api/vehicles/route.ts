import { prisma } from "@/lib/prisma";
import { ApiError, handle, parsePagination, parseQuery, readJson } from "@/lib/api";
import { notFound } from "@/lib/http";
import { vehicleFilterSchema, vehicleSchema } from "@/lib/schemas";
import { toVehicle } from "@/lib/serializers";

export const dynamic = "force-dynamic";

// Règle : catégorie ou agence inexistante -> 404
export async function ensureRefsExist(categoryId: string, agencyId: string) {
  const [cat, ag] = await Promise.all([
    prisma.category.findUnique({ where: { id: categoryId } }),
    prisma.agency.findUnique({ where: { id: agencyId } }),
  ]);
  if (!cat) throw new ApiError(notFound(`Catégorie ${categoryId} introuvable`));
  if (!ag) throw new ApiError(notFound(`Agence ${agencyId} introuvable`));
}

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