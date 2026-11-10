# services/vehicle-service

Service Next.js (port 8102, nom Eureka **RENTAL-VEHICLE**), base MySQL `vehicle_db` (Prisma).

## Installation (la première fois)

Prérequis : Node.js 22 ou 24, Docker Desktop avec l'infrastructure lancée (`infra/` : `docker compose up -d`).

```powershell
cd services\vehicle-service
copy .env.example .env          # puis remplir KEYCLOAK_CLIENT_SECRET (client vehicle-service du realm)
npm ci                          # installe les dépendances et génère le client Prisma (postinstall)
npx prisma migrate deploy       # crée les tables dans vehicle_db
npm run seed                    # données de démo : 2 agences, 3 catégories, 6 véhicules
```

Si le seed affiche « @prisma/client did not initialize yet », lancer `npx prisma generate` puis `npm run seed`.

## Lancement (deux fenêtres)

```powershell
npm run dev                     # API sur http://0.0.0.0:8102, inscription Eureka
npm run consumer                # consommateur RabbitMQ de vehicle.contract_events
```

## Variables d'environnement (`.env`, non commité — modèle : `.env.example`)

| Variable | Rôle |
| --- | --- |
| `SERVICE_PORT`, `SERVICE_HOST` | port HTTP (8102) et nom annoncé dans Eureka (`host.docker.internal`) |
| `DB_URL` | base MySQL `vehicle_db` |
| `SHADOW_DB_URL` | base temporaire, utile seulement pour `prisma migrate dev` |
| `EUREKA_URL` | serveur Eureka |
| `KEYCLOAK_ISSUER`, `KEYCLOAK_CLIENT_ID`, `KEYCLOAK_CLIENT_SECRET` | vérification des tokens et token de service |
| `RABBITMQ_URL` | broker RabbitMQ |
| `RESERVATION_MOCK` | `true` : réservations simulées ; `false` : appels réels à RENTAL-RESERVATION (intégration) |

## Tests

```powershell
npm test
```
