import { prisma } from "@/lib/prisma";
import { ApiError, handle, parseId, parsePagination, readJson } from "@/lib/api";
import { notFound } from "@/lib/http";
import { maintenanceSchema } from "@/lib/schemas";
import { toMaintenance } from "@/lib/serializers";
import { requireRoles, STAFF } from "@/lib/auth"; // ← AJOUT

export const dynamic = "force-dynamic";

type Ctx = { params: Promise<{ id: string }> };

async function ensureVehicle(id: string) {
  const v = await prisma.vehicle.findUnique({ where: { id } });
  if (!v) throw new ApiError(notFound(`Véhicule ${id} introuvable`));
}

export const GET = handle<Ctx>(async (req, { params }) => {
  await requireRoles(req, STAFF); // ← AJOUT
  const vehicleId = parseId((await params).id);
  await ensureVehicle(vehicleId);
  const { page, size, skip, take } = parsePagination(new URL(req.url));
  const [items, total] = await prisma.$transaction([
    prisma.maintenance.findMany({ where: { vehicleId }, skip, take, orderBy: { date: "desc" } }),
    prisma.maintenance.count({ where: { vehicleId } }),
  ]);
  return Response.json({ items: items.map(toMaintenance), page, size, total });
});

export const POST = handle<Ctx>(async (req, { params }) => {
  await requireRoles(req, STAFF); // ← AJOUT
  const vehicleId = parseId((await params).id);
  await ensureVehicle(vehicleId);
  const data = await readJson(req, maintenanceSchema);
  const m = await prisma.maintenance.create({
    data: { vehicleId, type: data.type, date: new Date(data.date), cost: data.cost },
  });
  return Response.json(toMaintenance(m), {
    status: 201,
    headers: { Location: `/api/vehicles/${vehicleId}/maintenances/${m.id}` },
  });
});