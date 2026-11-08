# Conventions communes — Location de voitures

## Équipe
| Membre | Service | Framework | Port | Base | Nom Eureka |
| --- | --- | --- | --- | --- | --- |
| Montassar | user-service | Spring Boot 3.2 | 8101 | MySQL user_db | RENTAL-USER |
| Yosra | vehicle-service | Next.js | 8102 | MySQL vehicle_db | RENTAL-VEHICLE |
| Aziz | reservation-service | Laravel 11 | 8103 | MySQL reservation_db | RENTAL-RESERVATION |
| Fadi | payment-service | Django REST | 8104 | PostgreSQL payment_db | RENTAL-PAYMENT |
| Rania | contract-service | ASP.NET Core 8 | 8105 | PostgreSQL contract_db | RENTAL-CONTRACT |
| Seddik | complaint-service | Symfony 7 | 8106 | MySQL complaint_db | RENTAL-COMPLAINT |
| Feryel | ml-service | FastAPI | 8107 | PostgreSQL ml_db | RENTAL-ML |

Infra : Eureka 8761, Gateway 8088, Keycloak 8180, RabbitMQ 5672/15672, MySQL 3306, PostgreSQL 5432, Angular 4200.

## Format des données
- Identifiants : UUID v4 en chaîne.
- JSON : clés en camelCase (forcer dans Django et Laravel).
- Dates : ISO 8601 ; `2026-11-08` pour une date, `2026-11-08T14:30:00Z` pour un instant (UTC).
- Montants : nombre décimal avec 3 décimales, devise `TND`.
- Liste paginée : `?page=0&size=20` -> `{ "items": [...], "page": 0, "size": 20, "total": 57 }`.
- Erreur : `{ "status": 409, "error": "CONFLICT", "message": "...", "details": [ { "field": "...", "message": "..." } ] }`.

## Codes HTTP
200 lecture/modification, 201 création (+ en-tête Location), 204 suppression, 400 requête invalide,
401 sans token, 403 rôle insuffisant, 404 introuvable, 409 conflit métier, 422 validation métier,
502 réponse illisible d'un autre service, 503 autre service indisponible.

## Routes
- Publiques : `/api/<ressources>` (pluriel, anglais), passent par la Gateway.
- Entre services : `/internal/<ressources>`, jamais routées par la Gateway, token de compte de service (rôle INTERNAL).
- Santé : `GET /health` -> `{ "status": "UP" }` sur chaque service.

## En-têtes
`Authorization: Bearer <token>`, `X-Correlation-Id` (à retransmettre tel quel), `Idempotency-Key` (paiements).

## Variables d'environnement
`SERVICE_PORT`, `DB_URL`, `EUREKA_URL`, `KEYCLOAK_ISSUER`, `KEYCLOAK_CLIENT_ID`, `KEYCLOAK_CLIENT_SECRET`, `RABBITMQ_URL`.

## Sécurité
Rôles du realm `location-voitures` : CLIENT, AGENT, ADMIN, INTERNAL. Les rôles sont dans la clé `roles` du token.

## RabbitMQ
- La file est déclarée par le consommateur (durable). Le producteur publie avec le nom seulement.
- Messages JSON persistants, enveloppe : `eventId`, `type`, `occurredAt`, `data` (exemples dans `docs/events/`).
- Consommateur : ack manuel, prefetch 1, une seule remise en file, puis file `<nom>.dlq`.
- Consommateur idempotent : ignorer un `eventId` déjà traité.

| File | Producteur -> consommateur | Message |
| --- | --- | --- |
| reservation.payment_succeeded | payment -> reservation | PaymentSucceeded |
| payment.reservation_cancelled | reservation -> payment | ReservationCancelled |
| vehicle.contract_events | contract -> vehicle | VehicleHandedOver, VehicleReturned |
| payment.return_settled | contract -> payment | ReturnSettled |
| reservation.contract_closed | contract -> reservation | ContractClosed |
| ml.reservation_completed | reservation -> ml | ReservationCompleted |

Tester un consommateur sans son producteur : console RabbitMQ -> Queues and Streams -> la file -> Publish message,
content_type `application/json`, Delivery mode 2 (Persistent), coller le JSON d'exemple.

## Git
Une branche `feature/<service>` par membre, fusion dans `main` par pull request relue, checklist du modèle de PR cochée.
