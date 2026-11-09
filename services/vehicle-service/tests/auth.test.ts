import { readFileSync } from "node:fs";
import path from "node:path";
import { beforeAll, describe, expect, it } from "vitest";
import { requireRoles } from "../lib/auth";
import { ApiError } from "../lib/api";

const TOKEN_URL = `${process.env.KEYCLOAK_ISSUER}/protocol/openid-connect/token`;

async function getToken(body: Record<string, string>) {
  const res = await fetch(TOKEN_URL, { method: "POST", body: new URLSearchParams(body) });
  if (!res.ok) throw new Error(`Keycloak ${res.status}`);
  return (await res.json()).access_token as string;
}

const request = (token?: string) =>
  new Request("http://localhost/internal/vehicles/x", {
    headers: token ? { authorization: `Bearer ${token}` } : {},
  });

async function statusOf(promise: Promise<unknown>) {
  try {
    await promise;
    return 200;
  } catch (e) {
    if (e instanceof ApiError) return e.response.status;
    throw e;
  }
}

let serviceToken: string;
let clientToken: string;

beforeAll(async () => {
  const realmFile = path.join(process.cwd(), "..", "..", "infra", "keycloak", "location-voitures-realm.json");
  const realm = JSON.parse(readFileSync(realmFile, "utf8"));
  const secret = realm.clients.find((c: any) => c.clientId === "vehicle-service").secret;

  serviceToken = await getToken({ grant_type: "client_credentials", client_id: "vehicle-service", client_secret: secret });
  clientToken = await getToken({ grant_type: "password", client_id: "rental-front", username: "client1", password: "client1_pwd" });
});

describe("Sécurité /internal (rôle INTERNAL)", () => {
  it("401 sans token", async () => {
    expect(await statusOf(requireRoles(request(), ["INTERNAL"]))).toBe(401);
  });

  it("401 avec un token invalide", async () => {
    expect(await statusOf(requireRoles(request("abc.def.ghi"), ["INTERNAL"]))).toBe(401);
  });

  it("403 avec un token sans le rôle INTERNAL (client1)", async () => {
    expect(await statusOf(requireRoles(request(clientToken), ["INTERNAL"]))).toBe(403);
  });

  it("accepte un token de service avec le rôle INTERNAL", async () => {
    expect(await statusOf(requireRoles(request(serviceToken), ["INTERNAL"]))).toBe(200);
  });
});