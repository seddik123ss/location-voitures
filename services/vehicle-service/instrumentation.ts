export async function register() {
  // Seulement côté serveur Node.js (pas dans le runtime "edge")
  if (process.env.NEXT_RUNTIME === "nodejs") {
    const { startEureka } = await import("./lib/eureka");
    startEureka();
  }
}