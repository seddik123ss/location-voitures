import { prisma } from "@/lib/prisma";
import { ApiError, handle, parseId, readJson } from "@/lib/api";
import { notFound } from "@/lib/http";
import { categorySchema } from "@/lib/schemas";
import { toCategory } from "@/lib/serializers";

export const dynamic = "force-dynamic";

type Ctx = { params: Promise<{ id: string }> };

export const GET = handle<Ctx>(async (_req, { params }) => {
  const id = parseId((await params).id);
  const c = await prisma.category.findUnique({ where: { id } });
  if (!c) throw new ApiError(notFound(`Catégorie ${id} introuvable`));
  return Response.json(toCategory(c));
});

export const PUT = handle<Ctx>(async (req, { params }) => {
  const id = parseId((await params).id);
  const data = await readJson(req, categorySchema);
  const c = await prisma.category.update({ where: { id }, data });
  return Response.json(toCategory(c));
});

export const DELETE = handle<Ctx>(async (_req, { params }) => {
  const id = parseId((await params).id);
  await prisma.category.delete({ where: { id } });
  return new Response(null, { status: 204 });
});