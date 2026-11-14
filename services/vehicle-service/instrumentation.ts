export async function register() {
  // Seulement côté serveur Node.js (pas dans le runtime "edge")
  if (process.env.NEXT_RUNTIME === "nodejs") {
    // Config Server d'abord (SERVICE_HOST, SERVICE_PORT, EUREKA_URL, KEYCLOAK_ISSUER absents du .env), puis Eureka
    const { loadConfigServer } = await import("./lib/config-server");
    await loadConfigServer();
    const { startEureka } = await import("./lib/eureka");
    startEureka();
  }
}
