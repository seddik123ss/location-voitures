/**
 * Configuration NON secrète lue au démarrage sur le Config Server (Spring Cloud Config, infra/config-server) :
 * GET {CONFIG_SERVER_URL}/rental-vehicle/default renvoie infra/config-repo/rental-vehicle.yml et application.yml.
 *
 * Seules les variables encore absentes de process.env sont remplies :
 * priorité variable d'environnement ou .env  >  Config Server  >  valeur par défaut dans le code.
 * Les secrets ne viennent jamais du Config Server. CONFIG_SERVER_URL= (vide) désactive l'appel.
 * Appelé une fois : instrumentation.ts (serveur Next.js) et consumer/index.ts (consommateur RabbitMQ).
 */

/** Clé du Config Server -> variable d'environnement lue par le code (lib/eureka.ts, lib/auth.ts...) */
export const CONFIG_KEYS: Record<string, string> = {
  "service.port": "SERVICE_PORT",
  "service.host": "SERVICE_HOST",
  "eureka.url": "EUREKA_URL",
  "keycloak.issuer": "KEYCLOAK_ISSUER",
};

const APPLICATION = "rental-vehicle";
const TIMEOUT_MS = 3000;

interface ConfigDocument {
  propertySources: { name: string; source: Record<string, unknown> }[];
}

export function configUrl(baseUrl: string): string {
  return `${baseUrl.replace(/\/$/, "")}/${APPLICATION}/default`;
}

/** propertySources : le fichier du service (le plus prioritaire) avant application.yml. */
export function extractValues(document: ConfigDocument): Record<string, string> {
  const merged: Record<string, unknown> = {};
  for (const source of [...document.propertySources].reverse()) Object.assign(merged, source.source);
  const values: Record<string, string> = {};
  for (const [key, variable] of Object.entries(CONFIG_KEYS)) {
    if (key in merged) values[variable] = String(merged[key]);
  }
  return values;
}

/** Remplit les variables absentes ; renvoie celles appliquées. Config Server injoignable : avertissement, rien ne change. */
export async function loadConfigServer(
  env: Record<string, string | undefined> = process.env,
): Promise<Record<string, string>> {
  const baseUrl = env.CONFIG_SERVER_URL ?? "http://localhost:8888";
  if (!baseUrl) return {};
  let values: Record<string, string>;
  try {
    const res = await fetch(configUrl(baseUrl), { signal: AbortSignal.timeout(TIMEOUT_MS) });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    values = extractValues((await res.json()) as ConfigDocument);
  } catch (e) {
    console.warn(`[config] Config Server injoignable (${configUrl(baseUrl)} : ${e instanceof Error ? e.message : e}) : .env et valeurs par défaut`);
    return {};
  }
  const applied: Record<string, string> = {};
  for (const [variable, value] of Object.entries(values)) {
    if (!env[variable]) {
      env[variable] = value;
      applied[variable] = value;
    }
  }
  if (Object.keys(applied).length > 0) {
    console.log(`[config] ${configUrl(baseUrl)} -> ${Object.keys(applied).sort().join(", ")}`);
  }
  return applied;
}
