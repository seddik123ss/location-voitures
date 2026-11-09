import { readFile } from "node:fs/promises";
import path from "node:path";

export const dynamic = "force-dynamic";

// Sert le fichier commun docs/openapi/vehicle-service.yaml du dépôt
export async function GET() {
  const file = path.join(process.cwd(), "..", "..", "docs", "openapi", "vehicle-service.yaml");
  const yaml = await readFile(file, "utf8");
  return new Response(yaml, {
    headers: { "Content-Type": "application/yaml; charset=utf-8" },
  });
}