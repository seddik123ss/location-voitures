import { Prisma } from "@prisma/client";
import type { ZodType } from "zod";
import { badRequest, conflict, errorResponse, notFound, UUID_RE } from "./http";

// Erreur qui porte directement la réponse HTTP à renvoyer
export class ApiError extends Error {
  constructor(public response: Response) {
    super("ApiError");
  }
}

// Lit le corps JSON et le valide avec Zod -> 400 si invalide
export async function readJson<T>(req: Request, schema: ZodType<T>): Promise<T> {
  let body: unknown;
  try {
    body = await req.json();
  } catch {
    throw new ApiError(badRequest("Corps JSON invalide"));
  }
  const result = schema.safeParse(body);
  if (!result.success) {
    throw new ApiError(
      badRequest(
        "Validation échouée",
        result.error.issues.map((i) => ({
          field: i.path.map(String).join(".") || "body",
          message: i.message,
        }))
      )
    );
  }
  return result.data;
}

// Vérifie qu'un identifiant est un UUID -> 400 sinon
export function parseId(id: string): string {
  if (!UUID_RE.test(id)) {
    throw new ApiError(badRequest("Identifiant invalide", [{ field: "id", message: "UUID attendu" }]));
  }
  return id;
}

// ?page=0&size=20 (size limité à 100)
export function parsePagination(url: URL) {
  const page = Math.max(0, Math.floor(Number(url.searchParams.get("page") ?? 0)) || 0);
  const rawSize = Math.floor(Number(url.searchParams.get("size") ?? 20)) || 20;
  const size = Math.min(100, Math.max(1, rawSize));
  return { page, size, skip: page * size, take: size };
}

// Enveloppe chaque route : traduit les erreurs en réponses au format commun
export function handle<C>(fn: (req: Request, ctx: C) => Promise<Response>) {
  return async (req: Request, ctx: C): Promise<Response> => {
    try {
      return await fn(req, ctx);
    } catch (e) {
      if (e instanceof ApiError) return e.response;
      if (e instanceof Prisma.PrismaClientKnownRequestError) {
        if (e.code === "P2002") {
          return conflict("Valeur déjà utilisée", [
            { field: String(e.meta?.target ?? ""), message: "doit être unique" },
          ]);
        }
        if (e.code === "P2025") return notFound("Ressource introuvable");
        if (e.code === "P2003") return conflict("Ressource encore utilisée par des véhicules");
      }
      console.error(e);
      return errorResponse(500, "INTERNAL_ERROR", "Erreur interne");
    }
  };
}