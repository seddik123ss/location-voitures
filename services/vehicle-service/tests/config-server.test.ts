import { afterEach, describe, expect, it, vi } from "vitest";
import { configUrl, extractValues, loadConfigServer } from "../lib/config-server";

// Réponse de GET /rental-vehicle/default : le fichier du service d'abord (le plus prioritaire), puis application.yml.
const RESPONSE = {
  name: "rental-vehicle",
  propertySources: [
    { name: "file:/config-repo/rental-vehicle.yml", source: { "service.port": 8102, "service.eureka-name": "RENTAL-VEHICLE" } },
    {
      name: "file:/config-repo/application.yml",
      source: {
        "service.host": "host.docker.internal",
        "eureka.url": "http://localhost:8761/eureka",
        "keycloak.issuer": "http://localhost:8180/realms/location-voitures",
        "service.port": 9999,
      },
    },
  ],
};

describe("Config Server", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("lit les 4 valeurs non secrètes, le fichier du service l'emporte", () => {
    expect(configUrl("http://localhost:8888/")).toBe("http://localhost:8888/rental-vehicle/default");
    expect(extractValues(RESPONSE)).toEqual({
      SERVICE_PORT: "8102",
      SERVICE_HOST: "host.docker.internal",
      EUREKA_URL: "http://localhost:8761/eureka",
      KEYCLOAK_ISSUER: "http://localhost:8180/realms/location-voitures",
    });
  });

  it("ne remplit que les variables absentes (le .env garde la priorité)", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => new Response(JSON.stringify(RESPONSE))));
    const env: Record<string, string | undefined> = { CONFIG_SERVER_URL: "http://cs:8888", EUREKA_URL: "http://eureka:8761/eureka" };

    const applied = await loadConfigServer(env);

    expect(env.EUREKA_URL).toBe("http://eureka:8761/eureka");
    expect(env.SERVICE_PORT).toBe("8102");
    expect(applied).not.toHaveProperty("EUREKA_URL");
  });

  it("Config Server injoignable : rien ne change", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => { throw new Error("ECONNREFUSED"); }));
    const env: Record<string, string | undefined> = { CONFIG_SERVER_URL: "http://cs:8888" };

    expect(await loadConfigServer(env)).toEqual({});
    expect(env).toEqual({ CONFIG_SERVER_URL: "http://cs:8888" });
  });

  it("désactivé si CONFIG_SERVER_URL est vide", async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);
    expect(await loadConfigServer({ CONFIG_SERVER_URL: "" })).toEqual({});
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
