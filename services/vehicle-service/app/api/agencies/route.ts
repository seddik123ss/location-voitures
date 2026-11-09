import { prisma } from "@/lib/prisma";
import { handle, parsePagination, readJson } from "@/lib/api";
import { agencySchema } from "@/lib/schemas";
import { toAgency } from "@/lib/serializers";
import { requireRoles, STAFF } from "@/lib/auth"; // ← AJOUT

export const dynamic = "force-dynamic";

export const GET = handle(async (req) => {
  const { page, size, skip, take } = parsePagination(new URL(req.url));
  const [items, total] = await prisma.$transaction([
    prisma.agency.findMany({ skip, take, orderBy: { city: "asc" } }),
    prisma.agency.count(),
  ]);
  return Response.json({ items: items.map(toAgency), page, size, total });
});

export const POST = handle(async (req) => {
  await requireRoles(req, STAFF); // ← AJOUT
  const data = await readJson(req, agencySchema);
  const a = await prisma.agency.create({ data });
  return Response.json(toAgency(a), {
    status: 201,
    headers: { Location: `/api/agencies/${a.id}` },
  });
});