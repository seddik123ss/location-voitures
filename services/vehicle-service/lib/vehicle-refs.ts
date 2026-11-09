import { prisma } from "./prisma";
import { ApiError } from "./api";
import { notFound } from "./http";

// Règle : catégorie ou agence inexistante -> 404
export async function ensureRefsExist(categoryId: string, agencyId: string) {
  const [cat, ag] = await Promise.all([
    prisma.category.findUnique({ where: { id: categoryId } }),
    prisma.agency.findUnique({ where: { id: agencyId } }),
  ]);
  if (!cat) throw new ApiError(notFound(`Catégorie ${categoryId} introuvable`));
  if (!ag) throw new ApiError(notFound(`Agence ${agencyId} introuvable`));
}