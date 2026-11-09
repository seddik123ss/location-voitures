import { z } from "zod";
import { ApiError } from "./api";
import { errorResponse, notFound } from "./http";

const APP = "RENTAL-RESERVATION";

const unavailable = (message = `${APP} indisponible`) =>
  new ApiError(errorResponse(503, "SERVICE_UNAVAILABLE", message));
const badGateway = (message = `Réponse illisible de ${APP}`) =>
  new ApiError(errorResponse(502, "BAD_GATEWAY", message));

// Simulation (RESERVATION_MOCK=true) : réservations actives de docs/demo-ids.md
const MOCK_ACTIVE: Record<string, string[]> = {
  "ecdcc60f-ed6a-4007-8622-2b568338ef7a": ["01cb68ad-4eec-4bdd-8971-ce35381c59d1"], // v1 -> r-pending
  "9f03ab2d-97a5-4c08-a952-dc6e446370d1": ["33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8"], // v2 -> r-confirmed
};

const activeSchema = z.object({
  vehicleId: z.string(),
  active: z.boolean(),
  reservationIds: z.array(z.string()),
});
export type ActiveReservations = z.infer<typeof activeSchema>;

// Token de service (client credentials), gardé en cache jusqu'à son expiration
let cachedToken: { value: string; expiresAt: number } | null = null;

async function getServiceToken(): Promise<string> {
  if (cachedToken && cachedToken.expiresAt > Date.now()) return cachedToken.value;

  let res: Response;
  try {
    res = await fetch(`${process.env.KEYCLOAK_ISSUER}/protocol/openid-connect/token`, {
      method: "POST",
      body: new URLSearchParams({
        grant_type: "client_credentials",
        client_id: process.env.KEYCLOAK_CLIENT_ID ?? "vehicle-service",
        client_secret: process.env.KEYCLOAK_CLIENT_SECRET ?? "",
      }),
      signal: AbortSignal.timeout(5000),
    });
  } catch {
    throw unavailable("Keycloak indisponible");
  }
  if (!res.ok) throw unavailable(`Token de service refusé par Keycloak (${res.status})`);

  const body = await res.json();
  cachedToken = { value: body.access_token, expiresAt: Date.now() + (body.expires_in - 30) * 1000 };
  return cachedToken.value;
}

// Adresse d'une instance UP trouvée dans Eureka (jamais d'URL en dur)
async function resolveService(app: string): Promise<string> {
  const eureka = (process.env.EUREKA_URL ?? "http://localhost:8761/eureka").replace(/\/$/, "");
  let res: Response;
  try {
    res = await fetch(`${eureka}/apps/${app}`, {
      headers: { Accept: "application/json" },
      signal: AbortSignal.timeout(3000),
    });
  } catch {
    throw unavailable("Eureka injoignable");
  }
  if (res.status === 404) throw unavailable(`${app} absent d'Eureka`);
  if (!res.ok) throw unavailable(`Eureka a répondu ${res.status}`);

  const body = await res.json().catch(() => null);
  const raw = body?.application?.instance;
  const instances: any[] = Array.isArray(raw) ? raw : raw ? [raw] : [];
  const up = instances.find((i) => i.status === "UP");
  if (!up) throw unavailable(`${app} : aucune instance UP dans Eureka`);

  return `http://${up.hostName}:${up.port.$}`;
}

// GET /internal/reservations/active?vehicleId= sur RENTAL-RESERVATION
export async function getActiveReservations(vehicleId: string): Promise<ActiveReservations> {
  if (process.env.RESERVATION_MOCK === "true") {
    const ids = MOCK_ACTIVE[vehicleId] ?? [];
    return { vehicleId, active: ids.length > 0, reservationIds: ids };
  }

  const base = await resolveService(APP);
  const token = await getServiceToken();

  let res: Response;
  try {
    res = await fetch(`${base}/internal/reservations/active?vehicleId=${encodeURIComponent(vehicleId)}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
      signal: AbortSignal.timeout(5000),
    });
  } catch {
    throw unavailable(); // service arrêté ou injoignable
  }

  if (res.status === 404) throw new ApiError(notFound(`${APP} : véhicule ${vehicleId} introuvable`));
  if (res.status === 503) throw unavailable();
  if (!res.ok) throw badGateway(`${APP} a répondu ${res.status}`);

  const parsed = activeSchema.safeParse(await res.json().catch(() => null));
  if (!parsed.success) throw badGateway();
  return parsed.data;
}