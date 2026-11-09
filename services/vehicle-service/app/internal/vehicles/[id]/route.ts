import { prisma } from "@/lib/prisma";
import { badRequest, notFound, UUID_RE } from "@/lib/http";

export const dynamic = "force-dynamic";

export async function GET(
  _req: Request,
  { params }: { params: Promise<{ id: string }> }
) {
  const { id } = await params;

  if (!UUID_RE.test(id)) {
    return badRequest("Identifiant invalide", [{ field: "id", message: "UUID attendu" }]);
  }

  const v = await prisma.vehicle.findUnique({
    where: { id },
    include: { category: true },
  });

  if (!v) return notFound(`Véhicule ${id} introuvable`);

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
}