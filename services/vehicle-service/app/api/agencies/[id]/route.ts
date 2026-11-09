import { prisma } from "@/lib/prisma";
import { ApiError, handle, parseId, readJson } from "@/lib/api";
import { notFound } from "@/lib/http";
import { agencySchema } from "@/lib/schemas";
import { toAgency } from "@/lib/serializers";
import { requireRoles, STAFF } from "@/lib/auth"; // ← AJOUT

export const dynamic = "force-dynamic";

type Ctx = { params: Promise<{ id: string }> };

export const GET = handle<Ctx>(async (_req, { params }) => {
  const id = parseId((await params).id);
  const a = await prisma.agency.findUnique({ where: { id } });
  if (!a) throw new ApiError(notFound(`Agence ${id} introuvable`));
  return Response.json(toAgency(a));
});

export const PUT = handle<Ctx>(async (req, { params }) => {
  await requireRoles(req, STAFF); // ← AJOUT
  const id = parseId((await params).id);
  const data = await readJson(req, agencySchema);
  const a = await prisma.agency.update({ where: { id }, data });
  return Response.json(toAgency(a));
});

export const DELETE = handle<Ctx>(async (req, { params }) => {
  await requireRoles(req, STAFF); // ← AJOUT
  const id = parseId((await params).id);
  await prisma.agency.delete({ where: { id } });
  return new Response(null, { status: 204 });
});