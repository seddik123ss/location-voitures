import { prisma } from "@/lib/prisma";
import { ApiError, handle, parseId, readJson } from "@/lib/api";
import { notFound } from "@/lib/http";
import { maintenanceSchema } from "@/lib/schemas";
import { toMaintenance } from "@/lib/serializers";

export const dynamic = "force-dynamic";

type Ctx = { params: Promise<{ id: string; maintenanceId: string }> };

// La maintenance doit exister ET appartenir à ce véhicule -> sinon 404
async function findOr404(ctx: Ctx) {
  const p = await ctx.params;
  const vehicleId = parseId(p.id);
  const id = parseId(p.maintenanceId);
  const m = await prisma.maintenance.findFirst({ where: { id, vehicleId } });
  if (!m) throw new ApiError(notFound(`Maintenance ${id} introuvable pour ce véhicule`));
  return m;
}

export const GET = handle<Ctx>(async (_req, ctx) => {
  return Response.json(toMaintenance(await findOr404(ctx)));
});

export const PUT = handle<Ctx>(async (req, ctx) => {
  const existing = await findOr404(ctx);
  const data = await readJson(req, maintenanceSchema);
  const m = await prisma.maintenance.update({
    where: { id: existing.id },
    data: { type: data.type, date: new Date(data.date), cost: data.cost },
  });
  return Response.json(toMaintenance(m));
});

export const DELETE = handle<Ctx>(async (_req, ctx) => {
  const existing = await findOr404(ctx);
  await prisma.maintenance.delete({ where: { id: existing.id } });
  return new Response(null, { status: 204 });
});