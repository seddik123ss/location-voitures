import amqp from "amqplib";
import { randomUUID } from "node:crypto";
import { readFileSync } from "node:fs";
import path from "node:path";

// Usage : npm run event -- VehicleHandedOver [--new-id] [--vehicle=<uuid>] [--type=<Type>]
const [name, ...opts] = process.argv.slice(2);
if (!name) {
  console.error("Usage : npm run event -- <VehicleHandedOver|VehicleReturned> [--new-id] [--vehicle=<uuid>] [--type=<Type>]");
  process.exit(1);
}

const file = path.join(process.cwd(), "..", "..", "docs", "events", `${name}.json`);
const msg = JSON.parse(readFileSync(file, "utf8"));

for (const o of opts) {
  if (o === "--new-id") msg.eventId = randomUUID();
  else if (o.startsWith("--vehicle=")) msg.data.vehicleId = o.slice("--vehicle=".length);
  else if (o.startsWith("--type=")) msg.type = o.slice("--type=".length);
}

async function main() {
  const conn = await amqp.connect(process.env.RABBITMQ_URL ?? "amqp://guest:guest@localhost:5672");
  const ch = await conn.createChannel();
  // Le producteur publie seulement avec le nom de la file
  ch.sendToQueue("vehicle.contract_events", Buffer.from(JSON.stringify(msg)), {
    persistent: true,
    contentType: "application/json",
  });
  console.log(`[publish] ${msg.type} eventId=${msg.eventId} vehicleId=${msg.data.vehicleId}`);
  await ch.close();
  await conn.close();
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});