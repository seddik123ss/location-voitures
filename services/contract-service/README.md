# contract-service — Contrats et retours de véhicules

Microservice de **Rania**, plateforme de location de voitures (ESPRIT, Applications Web Distribuées 2026-2027).

| | |
| --- | --- |
| Framework | ASP.NET Core 8 Web API, Entity Framework Core 8 + Npgsql |
| Port | **8105** |
| Base | PostgreSQL `contract_db` (utilisateur `contract_svc`) |
| Nom Eureka | **RENTAL-CONTRACT** (adresse annoncée `host.docker.internal`) |
| Routes Gateway | `/api/contracts/**`, `/api/inspections/**` |
| Route entre services | `GET /internal/contracts/{id}` (rôle INTERNAL, jamais exposée par la Gateway) |
| Swagger | http://localhost:8105/swagger — fichier : [`docs/openapi/contract-service.yaml`](../../docs/openapi/contract-service.yaml) |

## Rôle du service

Il gère le contrat de location, de la **remise des clés** jusqu'au **retour du véhicule** :

1. **Remise des clés** (AGENT) : vérifie la réservation et la caution, crée le contrat et l'état des lieux de départ.
2. **Retour** (AGENT) : état des lieux de retour, calcul automatique des frais supplémentaires, clôture du contrat.
3. **Consultation** : un CLIENT voit ses propres contrats, un AGENT ou un ADMIN les voit tous.

### Entités

| Entité | Champs |
| --- | --- |
| `Contract` | id, number (unique, `CTR-2026-0001`), reservationId, userId, vehicleId, signedAt, status (ACTIVE / CLOSED), closedAt |
| `Inspection` | id, contractId, type (PICKUP / RETURN), mileage, fuelLevel (0 à 1), damages, photos, inspectedAt |
| `ExtraFee` | id, contractId, type (LATE / FUEL / MILEAGE / DAMAGE), amount (TND, 3 décimales) |

`reservationId`, `userId` et `vehicleId` sont des UUID d'autres services : pas de clé étrangère entre bases.

### Règles métier

| Règle | Code |
| --- | --- |
| Réservation introuvable | 404 |
| La réservation doit être `CONFIRMED` | 409 |
| La caution doit être payée (paiements `DEPOSIT` au statut `SUCCEEDED` ≥ `depositAmount`) | 409 |
| Un seul contrat actif par réservation | 409 |
| Kilométrage au retour ≥ kilométrage au départ | 422 |
| Un contrat clôturé ne peut plus être modifié, supprimé ni rendu | 409 |
| Corps de requête invalide (champs manquants, `fuelLevel` hors 0..1...) | 400 |
| Service réservation / paiement / véhicule arrêté ou absent d'Eureka | 503 |
| Réponse illisible d'un autre service | 502 |
| RabbitMQ indisponible pendant une remise des clés ou un retour (rien n'est enregistré) | 503 |

**Frais calculés au retour** (paramètres dans la section `Fees` de `appsettings.json`) :

| Frais | Calcul |
| --- | --- |
| LATE | chaque jour entamé après 12:00 UTC le dernier jour de la réservation × tarif journalier de la catégorie du véhicule |
| FUEL | part du réservoir manquante × 140 TND (prix d'un plein) |
| MILEAGE | km au-delà de 250 km par jour réservé × 0.200 TND |
| DAMAGE | montant estimé par l'agent (`damageFee`) |

Les frais sont facturés à part : la caution est libérée en entier (`depositToRelease`), comme dans `docs/events/ReturnSettled.json`.

## Endpoints

| Méthode | Route | Rôles | Description |
| --- | --- | --- | --- |
| GET | `/health` | public | `{ "status": "UP" }` |
| GET | `/api/contracts?page=0&size=20&status=&userId=` | CLIENT, AGENT, ADMIN | liste paginée (un CLIENT ne voit que les siens) |
| GET | `/api/contracts/{id}` | CLIENT (le sien), AGENT, ADMIN | contrat + inspections + frais |
| POST | `/api/contracts` | AGENT, ADMIN | remise des clés → 201 + `Location` |
| PUT | `/api/contracts/{id}` | AGENT, ADMIN | modifie un contrat actif |
| DELETE | `/api/contracts/{id}` | AGENT, ADMIN | supprime un contrat actif → 204 |
| POST | `/api/contracts/{id}/return` | AGENT, ADMIN | retour, frais, clôture |
| GET | `/api/contracts/{id}/extra-fees` | CLIENT (le sien), AGENT, ADMIN | frais du contrat |
| GET | `/api/inspections?contractId=` | AGENT, ADMIN | liste paginée des états des lieux |
| GET / PUT | `/api/inspections/{id}` | AGENT, ADMIN | consulter / compléter (dommages, photos) tant que le contrat est actif |
| GET | `/internal/contracts/{id}` | INTERNAL | `{id, number, reservationId, userId, vehicleId, status, signedAt}` pour complaint-service |

Toutes les erreurs suivent le format commun : `{ "status", "error", "message", "details": [ { "field", "message" } ] }`.

## Communication avec les autres services

**Appels synchrones** (via Eureka, avec un token de compte de service Keycloak `contract-service`, rôle INTERNAL) :

| Service | Route | Utilisée pour |
| --- | --- | --- |
| RENTAL-RESERVATION | `GET /internal/reservations/{id}` | statut, dates, caution, client, véhicule |
| RENTAL-PAYMENT | `GET /internal/payments?reservationId=` | caution payée ? |
| RENTAL-VEHICLE | `GET /internal/vehicles/{id}` | tarif journalier (frais de retard) |

Tant que ces services ne tournent pas, `USE_FAKE_SERVICES` les simule avec les données de `docs/demo-ids.md`
(`src/ContractService/Clients/FakeRemoteClients.cs`), service par service :

| Valeur | Effet |
| --- | --- |
| `true` | réservation, paiement et véhicule simulés |
| `false` (ou vide) | les trois appelés pour de vrai via Eureka |
| `reservation,vehicle` | ces deux-là simulés, paiement appelé pour de vrai (exemple : test avec payment-service) |

Au démarrage, le journal affiche la liste des services simulés (`Services simulés : ...`).

**RabbitMQ** (contract-service est seulement producteur ; exchange par défaut, routing key = nom de la file, messages persistants JSON) :

| File | Message | Quand | Publication |
| --- | --- | --- | --- |
| `vehicle.contract_events` | VehicleHandedOver | remise des clés | dans la transaction (503 si RabbitMQ absent) |
| `vehicle.contract_events` | VehicleReturned | retour | dans la transaction |
| `payment.return_settled` | ReturnSettled (frais + `depositToRelease`) | retour | dans la transaction |
| `reservation.contract_closed` | ContractClosed | retour | best effort (simple log si RabbitMQ absent) |

Formats exacts : `docs/events/`.

## Lancer le service

Prérequis : .NET 8 SDK, Docker Desktop.

```powershell
# 1. Infrastructure (depuis la racine du dépôt)
cd infra
copy .env.example .env          # la première fois
docker compose up -d postgres rabbitmq keycloak eureka gateway

# 2. Configuration du service (la première fois)
cd ..\services\contract-service
copy .env.example .env          # puis remplir KEYCLOAK_CLIENT_SECRET

# 3. Lancer
cd src\ContractService
dotnet run
```

Au démarrage, les migrations sont appliquées automatiquement (tables + contrat de démo `c-completed`).
Ouvrir http://localhost:8105/swagger, cliquer sur **Authorize** et coller un token :

```powershell
# token d'agent (agent1 / agent1_pwd), à coller dans Swagger
$r = Invoke-RestMethod -Method Post http://localhost:8180/realms/location-voitures/protocol/openid-connect/token `
  -Body @{ grant_type='password'; client_id='rental-front'; username='agent1'; password='agent1_pwd' }
$r.access_token
```

Données pour essayer : réservation `33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8` (r-confirmed, remise des clés possible),
`01cb68ad-4eec-4bdd-8971-ce35381c59d1` (r-pending → 409).

### Variables d'environnement (`.env`, non commité — modèle : `.env.example`)

| Variable | Défaut | Rôle |
| --- | --- | --- |
| `SERVICE_PORT` | 8105 | port HTTP |
| `DB_URL` | `Host=localhost;Port=5432;Database=contract_db;...` | chaîne de connexion PostgreSQL |
| `EUREKA_URL` | `http://localhost:8761/eureka` | serveur Eureka |
| `KEYCLOAK_ISSUER` | `http://localhost:8180/realms/location-voitures` | émetteur des tokens |
| `KEYCLOAK_CLIENT_ID` / `KEYCLOAK_CLIENT_SECRET` | `contract-service` / — | token de service pour les appels `/internal` |
| `RABBITMQ_URL` | `amqp://guest:guest@localhost:5672` | broker |
| `USE_FAKE_SERVICES` | `false` | services simulés : `true` (tous), `false` (aucun) ou une liste parmi `reservation`, `payment`, `vehicle` |

## Tests

```powershell
cd services\contract-service
dotnet test
```

25 tests xUnit, sans Docker (base InMemory, RabbitMQ et Keycloak remplacés) :

- `ContractsApiTests` : CRUD, pagination, 400 / 404 / 409 / 422, Location, 401 / 403, un CLIENT ne voit que ses contrats, `/internal` réservé au rôle INTERNAL, messages RabbitMQ publiés ;
- `FeeCalculatorTests` : calcul des frais (dont le cas de `docs/events/ReturnSettled.json`).

Vérifié aussi avec l'infrastructure réelle : enregistrement Eureka, tokens Keycloak (client1, agent1, compte de service),
messages reçus dans les files RabbitMQ, 503 et annulation quand RabbitMQ est arrêté.

## Mettre à jour la doc OpenAPI

Le fichier `docs/openapi/contract-service.yaml` est généré depuis le code (commentaires `///` des contrôleurs) :

```powershell
cd services\contract-service
dotnet tool restore
dotnet build src\ContractService
$env:ASPNETCORE_ENVIRONMENT = "Testing"
dotnet swagger tofile --yaml --output ..\..\docs\openapi\contract-service.yaml src\ContractService\bin\Debug\net8.0\ContractService.dll v1
```

Puis remettre en tête du fichier la section `servers` (Gateway 8088 et accès direct 8105).

## Structure

```
services/contract-service/
├── src/ContractService/
│   ├── Controllers/     ContractsController, InspectionsController, InternalContractsController
│   ├── Services/        ContractManager (règles métier), FeeCalculator, FeeOptions
│   ├── Clients/         appels reservation / payment / vehicle (Eureka + token de service) et simulations
│   ├── Messaging/       publication RabbitMQ
│   ├── Security/        JWT Keycloak, rôles, Swagger
│   ├── Data/            DbContext, migrations, données de démo
│   ├── Models/ Dtos/ Errors/
│   └── Program.cs
└── tests/ContractService.Tests/
```

Frontend Angular du module : `frontend/src/app/features/contracts/` (liste « Mes contrats » pour CLIENT,
remise des clés et retour du véhicule pour AGENT).

## Usage de l'IA

J'ai utilisé **Claude (Anthropic)**, dans l'application Claude Code, comme assistant pendant tout le développement :

- installation de l'environnement Windows (Git, .NET 8, Docker/WSL, Node.js) et diagnostic des erreurs ;
- génération du code du service (entités, migrations, contrôleurs, règles métier, calcul des frais, Eureka, Keycloak, RabbitMQ),
  des tests xUnit, des pages Angular, de ce README et du fichier OpenAPI, à partir des conventions de l'équipe
  (`docs/conventions.md`, `docs/internal-api.md`, `docs/events/`, `docs/demo-ids.md`) ;
- exécution des tests et vérifications avec l'infrastructure réelle (Keycloak, Eureka, RabbitMQ, PostgreSQL).

Choix proposés par l'IA et à valider : les valeurs des frais (heure limite 12:00 UTC, plein à 140 TND,
250 km inclus par jour à 0.200 TND le km supplémentaire), réglables dans `appsettings.json`, et l'hypothèse que la caution
est un paiement `DEPOSIT` au statut `SUCCEEDED` (à confirmer avec Fadi, payment-service).
