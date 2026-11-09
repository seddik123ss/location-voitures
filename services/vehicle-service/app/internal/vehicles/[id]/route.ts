import { prisma } from "@/lib/prisma";
import { ApiError, handle, parseId } from "@/lib/api";
import { notFound } from "@/lib/http";
import { requireRoles } from "@/lib/auth";

export const dynamic = "force-dynamic";

type Ctx = { params: Promise<{ id: string }> };

// Route entre services : token de compte de service avec le rôle INTERNAL
export const GET = handle<Ctx>(async (req, { params }) => {
  await requireRoles(req, ["INTERNAL"]);
  const id = parseId((await params).id);

  const v = await prisma.vehicle.findUnique({
    where: { id },
    include: { category: true },
  });
  if (!v) throw new ApiError(notFound(`Véhicule ${id} introuvable`));

  // Format exact de docs/internal-api.md
  return Response.json({
    id: v.id,
    plate: v.plate,
    brand: v.brand,
    model: v.model,
    status: v.status,
    mileage: v.mileage,
    agencyId: v.agencyId,
    category: {
      id: v.category.id,
      name: v.category.name,
      dailyRate: v.category.dailyRate.toNumber(),
      deposit: v.category.deposit.toNumber(),
    },
  });
});