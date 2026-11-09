import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { getActiveReservations } from "../lib/reservation-client";
import { ApiError } from "../lib/api";

const V1 = "ecdcc60f-ed6a-4007-8622-2b568338ef7a";
const V3 = "a1154300-bea1-4072-b750-2b00cf1f0bcd";

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });

const eurekaUp = () =>
  json({ application: { name: "RENTAL-RESERVATION", instance: [{ hostName: "host.docker.internal", port: { $: 8103 }, status: "UP" }] } });

// Faux réseau : Eureka, Keycloak et reservation-service
function mockNetwork(opts: { eureka?: () => Response; reservation?: () => Response }) {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes("/eureka/apps/")) return (opts.eureka ?? eurekaUp)();
      if (url.includes("/openid-connect/token")) return json({ access_token: "fake", expires_in: 300 });
      if (url.includes("/internal/reservations/active")) return opts.reservation!();
      throw new Error(`URL inattendue : ${url}`);
    })
  );
}

async function statusOf(promise: Promise<unknown>) {
  try {
    await promise;
    return 200;
  } catch (e) {
    if (e instanceof ApiError) return e.response.status;
    throw e;
  }
}

beforeEach(() => {
  process.env.RESERVATION_MOCK = "false";
});
afterEach(() => {
  vi.unstubAllGlobals();
  process.env.RESERVATION_MOCK = "true";
});

describe("Appel à RENTAL-RESERVATION", () => {
  it("mode simulé : v1 a une réservation active", async () => {
    process.env.RESERVATION_MOCK = "true";
    expect((await getActiveReservations(V1)).active).toBe(true);
  });

  it("réservation active -> active: true", async () => {
    mockNetwork({ reservation: () => json({ vehicleId: V3, active: true, reservationIds: ["r1"] }) });
    expect(await getActiveReservations(V3)).toEqual({ vehicleId: V3, active: true, reservationIds: ["r1"] });
  });

  it("aucune réservation -> active: false", async () => {
    mockNetwork({ reservation: () => json({ vehicleId: V3, active: false, reservationIds: [] }) });
    expect((await getActiveReservations(V3)).active).toBe(false);
  });

  it("absent d'Eureka -> 503", async () => {
    mockNetwork({ eureka: () => new Response(null, { status: 404 }) });
    expect(await statusOf(getActiveReservations(V3))).toBe(503);
  });

  it("aucune instance UP -> 503", async () => {
    mockNetwork({ eureka: () => json({ application: { instance: [{ hostName: "h", port: { $: 8103 }, status: "DOWN" }] } }) });
    expect(await statusOf(getActiveReservations(V3))).toBe(503);
  });

  it("service injoignable -> 503", async () => {
    mockNetwork({ reservation: () => { throw new TypeError("fetch failed"); } });
    expect(await statusOf(getActiveReservations(V3))).toBe(503);
  });

  it("objet distant introuvable -> 404", async () => {
    mockNetwork({ reservation: () => new Response(null, { status: 404 }) });
    expect(await statusOf(getActiveReservations(V3))).toBe(404);
  });

  it("erreur 500 du service -> 502", async () => {
    mockNetwork({ reservation: () => new Response("boom", { status: 500 }) });
    expect(await statusOf(getActiveReservations(V3))).toBe(502);
  });

  it("réponse illisible -> 502", async () => {
    mockNetwork({ reservation: () => json({ foo: "bar" }) });
    expect(await statusOf(getActiveReservations(V3))).toBe(502);
  });
});