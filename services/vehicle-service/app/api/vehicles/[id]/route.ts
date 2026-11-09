import { prisma } from "@/lib/prisma";
import { ApiError, handle, parseId, readJson } from "@/lib/api";
import { conflict, notFound } from "@/lib/http";
import { vehicleSchema } from "@/lib/schemas";
import { toVehicle } from "@/lib/serializers";
import { getActiveReservations } from "@/lib/reservation-client";
import { ensureRefsExist } from "@/lib/vehicle-refs";
import { requireRoles, STAFF } from "@/lib/auth"; // ← AJOUT

export const dynamic = "force-dynamic";

type Ctx = { params: Promise<{ id: string }> };

async function findOr404(id: string) {
  const v = await prisma.vehicle.findUnique({
    where: { id },
    include: { category: true, agency: true },
  });
  if (!v) throw new ApiError(notFound(`Véhicule ${id} introuvable`));
  return v;
}

export const GET = handle<Ctx>(async (_req, { params }) => {
  const id = parseId((await params).id);
  return Response.json(toVehicle(await findOr404(id)));
});

export const PUT = handle<Ctx>(async (req, { params }) => {
  await requireRoles(req, STAFF); // ← AJOUT
  const id = parseId((await params).id);
  await findOr404(id);
  const data = await readJson(req, vehicleSchema);
  await ensureRefsExist(data.categoryId, data.agencyId);
  const v = await prisma.vehicle.update({
    where: { id },
    data,
    include: { category: true, agency: true },
  });
  return Response.json(toVehicle(v));
});

// Règle : supprimer un véhicule loué ou réservé -> 409
export const DELETE = handle<Ctx>(async (req, { params }) => {
  await requireRoles(req, STAFF); // ← AJOUT
  const id = parseId((await params).id);
  const v = await findOr404(id);

  if (v.status === "RENTED") {
    throw new ApiError(conflict("Impossible de supprimer un véhicule actuellement loué"));
  }

  const active = await getActiveReservations(id);
  if (active.active) {
    throw new ApiError(
      conflict("Impossible de supprimer un véhicule réservé", [
        { field: "reservationIds", message: active.reservationIds.join(", ") },
      ])
    );
  }

  await prisma.vehicle.delete({ where: { id } });
  return new Response(null, { status: 204 });
});