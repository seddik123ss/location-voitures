import { Eureka } from "eureka-js-client";

let client: Eureka | null = null;

export function getEurekaClient() {
  return client;
}

export function startEureka() {
  if (client) return; // déjà démarré

  const host = process.env.SERVICE_HOST ?? "host.docker.internal";
  const port = Number(process.env.SERVICE_PORT ?? 8102);
  const eurekaUrl = new URL(process.env.EUREKA_URL ?? "http://localhost:8761/eureka");
  const base = `http://${host}:${port}`;

  client = new Eureka({
    instance: {
      app: "RENTAL-VEHICLE",
      instanceId: `${host}:rental-vehicle:${port}`,
      hostName: host,
      ipAddr: host,
      port: { $: port, "@enabled": true },
      vipAddress: "RENTAL-VEHICLE",
      homePageUrl: `${base}/`,
      statusPageUrl: `${base}/docs`, // lien du tableau de bord Eureka : Swagger UI
      healthCheckUrl: `${base}/health`,
      dataCenterInfo: {
        "@class": "com.netflix.appinfo.InstanceInfo$DefaultDataCenterInfo",
        name: "MyOwn",
      },
    },
    eureka: {
      host: eurekaUrl.hostname,
      port: Number(eurekaUrl.port || 80),
      servicePath: `${eurekaUrl.pathname.replace(/\/$/, "")}/apps/`,
      maxRetries: 10,
      requestRetryDelay: 3000,
    },
  });

  client.start((err) => {
    if (err) console.error("[eureka] inscription échouée :", err.message);
    else console.log("[eureka] RENTAL-VEHICLE inscrit");
  });

  // Désinscription à l'arrêt (Ctrl + C)
  const stop = () => {
    console.log("[eureka] désinscription…");
    // Eureka injoignable : on quitte quand même au bout de 5 s
    setTimeout(() => process.exit(0), 5000).unref();
    client?.stop(() => process.exit(0));
  };
  process.once("SIGINT", stop);
  process.once("SIGTERM", stop);
}