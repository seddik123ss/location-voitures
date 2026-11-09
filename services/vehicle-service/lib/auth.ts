import { createRemoteJWKSet, jwtVerify, type JWTPayload } from "jose";
import { ApiError } from "./api";
import { errorResponse } from "./http";

const issuer = process.env.KEYCLOAK_ISSUER ?? "http://localhost:8180/realms/location-voitures";

// Clés publiques du realm (mises en cache par jose)
const JWKS = createRemoteJWKSet(new URL(`${issuer}/protocol/openid-connect/certs`));

const unauthorized = (message: string) => new ApiError(errorResponse(401, "UNAUTHORIZED", message));
const forbidden = (message: string) => new ApiError(errorResponse(403, "FORBIDDEN", message));

// Rôles dans la claim "roles" (convention de l'équipe), avec repli sur realm_access.roles
function rolesOf(payload: JWTPayload): string[] {
  const direct = payload.roles;
  if (Array.isArray(direct)) return direct.map(String);
  const realm = (payload.realm_access as { roles?: unknown } | undefined)?.roles;
  return Array.isArray(realm) ? realm.map(String) : [];
}

// 401 sans token ou token invalide, 403 si aucun des rôles demandés
export async function requireRoles(req: Request, allowed: string[]): Promise<JWTPayload> {
  const header = req.headers.get("authorization") ?? "";
  if (!header.startsWith("Bearer ")) throw unauthorized("Token manquant");

  let payload: JWTPayload;
  try {
    ({ payload } = await jwtVerify(header.slice(7), JWKS, { issuer }));
  } catch {
    throw unauthorized("Token invalide ou expiré");
  }

  const roles = rolesOf(payload);
  if (!allowed.some((r) => roles.includes(r))) {
    throw forbidden(`Rôle requis : ${allowed.join(" ou ")}`);
  }
  return payload;
}

// Rôles autorisés à gérer la flotte
export const STAFF = ["AGENT", "ADMIN"];