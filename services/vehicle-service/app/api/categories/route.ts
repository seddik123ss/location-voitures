import { prisma } from "@/lib/prisma";
import { handle, parsePagination, readJson } from "@/lib/api";
import { categorySchema } from "@/lib/schemas";
import { toCategory } from "@/lib/serializers";
import { requireRoles, STAFF } from "@/lib/auth"; // ← AJOUT

export const dynamic = "force-dynamic";

export const GET = handle(async (req) => {
  const { page, size, skip, take } = parsePagination(new URL(req.url));
  const [items, total] = await prisma.$transaction([
    prisma.category.findMany({ skip, take, orderBy: { name: "asc" } }),
    prisma.category.count(),
  ]);
  return Response.json({ items: items.map(toCategory), page, size, total });
});

export const POST = handle(async (req) => {
  await requireRoles(req, STAFF); // ← AJOUT
  const data = await readJson(req, categorySchema);
  const c = await prisma.category.create({ data });
  return Response.json(toCategory(c), {
    status: 201,
    headers: { Location: `/api/categories/${c.id}` },
  });
});