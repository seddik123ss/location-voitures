# reservation-service

Service des **réservations** de l'application de location de voitures (module d'Aziz).
Un client réserve un véhicule sur une période ; le service vérifie son permis et la disponibilité du véhicule, calcule le prix,
puis suit la réservation jusqu'à la fin de la location (paiement, remise du véhicule, clôture du contrat).

| | |
| --- | --- |
| Pile | Laravel 12, PHP 8.5, Eloquent, MySQL 8.4 (`reservation_db`), php-amqplib, firebase/php-jwt |
| Port | 8103 (`0.0.0.0`) |
| Nom Eureka | `RENTAL-RESERVATION`, annoncé sous `host.docker.internal` |
| Routes publiques | `/api/reservations/**`, via la Gateway (http://localhost:8088 → `lb://RENTAL-RESERVATION`) |
| Routes entre services | `/internal/reservations/**` (token de compte de service, rôle INTERNAL) |
| Sécurité | access token Keycloak (realm `location-voitures`), vérifié par le service (JWKS) |
| Documentation | Swagger UI http://localhost:8103/api/doc ; contrat partagé `docs/openapi/reservation-service.yaml` |
| Interface | pages Angular dans `frontend/src/app/features/reservations/` |

Conventions communes : `docs/conventions.md`, `docs/internal-api.md`, `docs/events/README.md`, `docs/demo-ids.md`.

> **Laravel 12 et non 11** : Composer refuse toutes les versions de Laravel 11, touchées par des failles de sécurité
> qui ne sont plus corrigées. Le code n'utilise rien de propre à la version 12.

## Sommaire

- [Lancer en local](#lancer-en-local)
- [Lancer en Docker](#lancer-en-docker)
- [Endpoints](#endpoints)
- [Règles métier et codes de réponse](#règles-métier-et-codes-de-réponse)
- [Messages RabbitMQ](#messages-rabbitmq)
- [Appels aux autres services](#appels-aux-autres-services)
- [Variables d'environnement](#variables-denvironnement)
- [Documentation OpenAPI](#documentation-openapi)
- [Pages Angular](#pages-angular)
- [Tests](#tests)
- [Données de démonstration](#données-de-démonstration)
- [Utilisation de l'IA](#utilisation-de-lia)

## Lancer en local

L'infrastructure doit tourner : dans `infra/`, `copy .env.example .env` puis `docker compose up -d`
(MySQL, Keycloak, Eureka, Gateway, RabbitMQ). Prérequis PHP : extensions `pdo_mysql`, `curl`, `mbstring`, `sockets`
(et `pdo_sqlite` pour les tests), à activer dans `php.ini`.

Première installation, dans `services/reservation-service` (PowerShell) :

```
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed          # tables + réservations de docs/demo-ids.md
```

Dans `.env` (jamais versionné), renseigner le secret du client Keycloak `reservation-service`
(champ `secret` du client dans `infra/keycloak/location-voitures-realm.json`) : `KEYCLOAK_CLIENT_SECRET=...`.
Il ne sert qu'aux appels vers les autres services (`LOOKUP_MODE=eureka`).

Puis **trois terminaux** :

```
composer serve      # API sur http://0.0.0.0:8103
composer eureka     # enregistrement RENTAL-RESERVATION, battement toutes les 30 s ; Ctrl+C retire l'instance
composer consume    # consommateur RabbitMQ (reservation.payment_succeeded, reservation.contract_closed)
```

| Commande | Rôle |
| --- | --- |
| `php artisan eureka:run` (`composer eureka`) | S'enregistre dans Eureka, se réenregistre si Eureka redémarre, se retire à l'arrêt (Ctrl+C) |
| `php artisan eureka:deregister` | Retire une instance restée inscrite (fenêtre fermée sans Ctrl+C) |
| `php artisan rabbitmq:consume` (`composer consume`) | Déclare les files du service puis consomme ; Ctrl+C arrête après le message en cours |
| `php artisan rabbitmq:consume --declare-only` | Crée seulement les files (débloque payment-service sans consommer) |
| `php artisan rabbitmq:consume --queue=reservation.contract_closed` | Une seule file |

`composer serve` lit sa configuration dans `.env` uniquement : une variable définie dans la session PowerShell (`$env:...`) est ignorée.

Vérifier : `curl http://localhost:8103/health` → `{"status":"UP"}`, et RENTAL-RESERVATION UP sur http://localhost:8761.
Le script `scripts/test-internal.ps1` appelle les routes `/internal` avec un vrai token Keycloak (200, 401, 403, 404).

## Lancer en Docker

Une image (`Dockerfile`, PHP 8.5, extensions `pdo_mysql`, `sockets`, `bcmath`, `pcntl`), trois conteneurs :

| Conteneur | Commande | Rôle |
| --- | --- | --- |
| `reservation-service` | `serve` (par défaut) | migrations (en attendant MySQL), données de démo si la base est vide, API sur `0.0.0.0:8103` avec 4 processus |
| `reservation-eureka` | `php artisan eureka:run` | enregistrement RENTAL-RESERVATION ; `docker stop` le retire d'Eureka |
| `reservation-consumer` | `php artisan rabbitmq:consume` | consommateur RabbitMQ ; `docker stop` l'arrête après le message en cours |

Le client Eureka partagé (`shared/php-eureka`) est hors du dossier du service : il arrive par le contexte de build nommé `shared`.
Construire à la main, depuis `services/reservation-service` :

```
docker build --build-context shared=../../shared -t location-voitures/reservation-service .
```

Bloc à ajouter dans `infra/docker-compose.yml` (profil `services`, comme complaint-service), et la ligne
`RESERVATION_KEYCLOAK_CLIENT_SECRET=` (secret de dev du client `reservation-service`) dans `infra/.env.example` :

```yaml
x-reservation-env: &reservation-env
  DB_CONNECTION: mysql
  DB_URL: mysql://reservation_svc:reservation_pwd@mysql:3306/reservation_db
  EUREKA_URL: http://eureka:8761/eureka
  SERVICE_HOST: host.docker.internal
  SERVICE_PORT: "8103"
  # L'émetteur des tokens reste localhost:8180 ; le conteneur joint Keycloak par keycloak:8080.
  KEYCLOAK_ISSUER: http://localhost:8180/realms/location-voitures
  KEYCLOAK_JWKS: http://keycloak:8080/realms/location-voitures/protocol/openid-connect/certs
  KEYCLOAK_TOKEN_URL: http://keycloak:8080/realms/location-voitures/protocol/openid-connect/token
  KEYCLOAK_CLIENT_ID: reservation-service
  KEYCLOAK_CLIENT_SECRET: ${RESERVATION_KEYCLOAK_CLIENT_SECRET:-}
  RABBITMQ_URL: amqp://guest:guest@rabbitmq:5672
  LOOKUP_MODE: ${RESERVATION_LOOKUP_MODE:-demo}

services:
  reservation-service:
    profiles: [services]
    build:
      context: ../services/reservation-service
      additional_contexts:
        shared: ../shared
    image: location-voitures/reservation-service
    ports: ["8103:8103"]
    environment: *reservation-env
    depends_on: [mysql, eureka, keycloak, rabbitmq]
    healthcheck:
      test: ["CMD", "php", "-r", "exit(@file_get_contents('http://127.0.0.1:8103/health') === false ? 1 : 0);"]
      interval: 10s
      timeout: 3s
      start_period: 20s
      retries: 3
  reservation-eureka:
    profiles: [services]
    image: location-voitures/reservation-service
    command: ["php", "artisan", "eureka:run"]
    environment: *reservation-env
    depends_on:
      reservation-service:
        condition: service_healthy
    stop_grace_period: 15s
  reservation-consumer:
    profiles: [services]
    image: location-voitures/reservation-service
    command: ["php", "artisan", "rabbitmq:consume"]
    environment: *reservation-env
    depends_on:
      reservation-service:
        condition: service_healthy
    stop_grace_period: 15s
```

Puis, dans `infra/` : `docker compose --profile services up -d --build reservation-service reservation-eureka reservation-consumer`.
Arrêter ces conteneurs avant `composer serve` en local : ils utilisent le même port 8103.

## Endpoints

### API publique (via la Gateway) : token avec le rôle CLIENT, AGENT ou ADMIN

| Méthode et route | Qui | Rôle | Succès |
| --- | --- | --- | --- |
| `GET /api/reservations` | tous | Liste paginée, plus récentes d'abord ; filtres `page`, `size` (1 à 100), `status`, `userId`, `vehicleId`. Un CLIENT ne voit que les siennes. | 200 |
| `POST /api/reservations/quote` | tous | Devis sans création : mêmes contrôles que la création, prix fixe et prix conseillé par ml-service. | 200 |
| `POST /api/reservations` | tous | Crée une réservation PENDING. Un AGENT ou un ADMIN donne le `userId` du client. | 201 + `Location` |
| `GET /api/reservations/{id}` | propriétaire, AGENT, ADMIN | Détail avec `statusHistory` et `allowedTransitions`. | 200 |
| `PUT /api/reservations/{id}` | propriétaire, AGENT, ADMIN | Nouvelles dates ou agence de retour, en PENDING seulement ; prix recalculé. | 200 |
| `POST /api/reservations/{id}/cancel` | propriétaire, AGENT, ADMIN | Annule (PENDING ou CONFIRMED), motif facultatif `{"reason": "..."}`. | 200 |
| `PATCH /api/reservations/{id}/status` | AGENT, ADMIN | Change le statut : `{"status": "ONGOING", "comment": "..."}`. | 200 |
| `DELETE /api/reservations/{id}` | ADMIN | Supprime une réservation CANCELLED. | 204 |

Corps de création :

```json
{
  "vehicleId": "2657e174-f016-4f0c-9485-0c63141432e3",
  "startDate": "2026-12-01",
  "endDate": "2026-12-04",
  "pickupAgencyId": "eb825ac4-855b-499b-b37a-b4c37bbd6b4e",
  "returnAgencyId": "1deb1dea-ab54-436d-8ed7-c3322f782c88",
  "userId": "f4d1bab3-468d-428d-b02c-b44341430bfd"
}
```

`pickupAgencyId` (facultatif) doit être l'agence du véhicule ; `returnAgencyId` vaut par défaut l'agence de départ ;
`userId` est ignoré pour un CLIENT (c'est lui) et obligatoire pour un AGENT ou un ADMIN.

Réponse (détail) :

```json
{
  "id": "33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8",
  "userId": "f4d1bab3-468d-428d-b02c-b44341430bfd",
  "vehicleId": "9f03ab2d-97a5-4c08-a952-dc6e446370d1",
  "pickupAgencyId": "eb825ac4-855b-499b-b37a-b4c37bbd6b4e",
  "returnAgencyId": "eb825ac4-855b-499b-b37a-b4c37bbd6b4e",
  "startDate": "2026-11-20",
  "endDate": "2026-11-25",
  "status": "CONFIRMED",
  "totalPrice": 450.000,
  "depositAmount": 500.000,
  "currency": "TND",
  "categoryId": "94236cd0-16fc-41e0-bfc2-c8b1d2c8b1fc",
  "allowedTransitions": ["ONGOING", "CANCELLED"],
  "createdAt": "2026-11-08T14:00:00Z",
  "updatedAt": "2026-11-08T14:30:00Z",
  "statusHistory": [
    { "fromStatus": null, "toStatus": "PENDING", "changedAt": "2026-11-08T14:00:00Z", "changedBy": "f4d1bab3-468d-428d-b02c-b44341430bfd", "comment": null },
    { "fromStatus": "PENDING", "toStatus": "CONFIRMED", "changedAt": "2026-11-08T14:30:00Z", "changedBy": null, "comment": "Paiement RENTAL reçu" }
  ]
}
```

Montants : nombres JSON à 3 décimales, en TND. `changedBy` est `null` pour un changement automatique (message RabbitMQ).

### Routes entre services : token de compte de service avec le rôle INTERNAL

| Route | Réponse | Appelée par |
| --- | --- | --- |
| `GET /internal/reservations/{id}` | `{id, userId, vehicleId, pickupAgencyId, returnAgencyId, startDate, endDate, status, totalPrice, depositAmount, currency}` | payment, contract, complaint |
| `GET /internal/reservations?userId=&page=0&size=20` | `{items: [...même format], page, size, total}` | user (OpenFeign) |
| `GET /internal/reservations/active?vehicleId=` | `{vehicleId, active, reservationIds}` (PENDING, CONFIRMED ou ONGOING) | vehicle |
| `GET /health` | `{"status": "UP"}` (public) | Eureka, Gateway |

## Règles métier et codes de réponse

**Cycle de vie** (`app/Enums/ReservationStatus.php`) :

```
PENDING ──► CONFIRMED ──► ONGOING ──► COMPLETED
   │            │
   └────────────┴──► CANCELLED
```

| Transition | Déclenchée par |
| --- | --- |
| PENDING → CONFIRMED | message PaymentSucceeded de type RENTAL, ou AGENT / ADMIN |
| CONFIRMED → ONGOING | AGENT / ADMIN à la remise du véhicule ; automatiquement à la réception de ContractClosed si ce n'était pas fait |
| ONGOING → COMPLETED | message ContractClosed, ou AGENT / ADMIN |
| PENDING ou CONFIRMED → CANCELLED | le client, un AGENT ou un ADMIN (`/cancel`) |

COMPLETED et CANCELLED sont finaux. Chaque changement est gardé dans `reservation_status_history` (la création en est la première ligne).

**Création, dans cet ordre :**

| # | Règle | Code |
| --- | --- | --- |
| 6 | `startDate` ≥ aujourd'hui (heure de Tunis), `endDate` > `startDate`, champs valides | 400 |
| 1 | Le client existe (`GET /internal/users/{id}`) | 404 |
| 2 | Compte actif, permis présent, vérifié et valide jusqu'à `endDate` inclus (client2 : expiré le 2026-06-30) | 422 |
| 3 | Le véhicule existe (`GET /internal/vehicles/{id}`) | 404 |
| 4 | Le véhicule n'est ni `MAINTENANCE` ni `OUT_OF_SERVICE` | 409 |
| | `pickupAgencyId` = agence du véhicule | 422 |
| 5 | Pas de chevauchement avec une réservation PENDING, CONFIRMED ou ONGOING du véhicule (un retour le jour d'un départ est permis) | 409 |
| 7 | `totalPrice` = (endDate − startDate) jours × `dailyRate` de la catégorie ; `depositAmount` = `deposit` de la catégorie | |

**Prix conseillé.** Le devis (`/quote`) appelle aussi `POST /internal/ml/price-predictions` sur RENTAL-ML
(`{categoryId, agencyId, startDate, endDate}` → `{dailyPrice, lowerBound, upperBound, modelVersion}`) et renvoie
`suggestedPrice`. C'est une information pour le client : le prix facturé reste le prix fixe, que payment-service compare au
paiement RENTAL. Si le modèle est absent, en erreur ou illisible, `suggestedPrice` vaut `null` (repli sur le prix fixe) ;
en `LOOKUP_MODE=demo`, il n'y a pas d'appel et `suggestedPrice` vaut `null`.

| Code | Quand |
| --- | --- |
| 400 | Champ manquant ou invalide, dates (`details` liste les champs) |
| 401 | Token absent, invalide, mal signé, d'un autre émetteur ou expiré |
| 403 | Rôle insuffisant, réservation d'un autre client, CLIENT qui réserve ou filtre pour un autre |
| 404 | Réservation, client ou véhicule introuvable ; route inconnue |
| 409 | Véhicule hors service ou déjà réservé, transition interdite (transitions possibles dans `details`), modification hors PENDING |
| 422 | Permis absent, non vérifié ou expiré, compte désactivé, mauvaise agence de départ |
| 502 | Réponse illisible de user-service ou vehicle-service |
| 503 | user-service, vehicle-service, Eureka, Keycloak ou RabbitMQ indisponible |

Toutes les erreurs suivent le format commun `{status, error, message, details}` et portent l'en-tête `X-Correlation-Id`.

## Messages RabbitMQ

| File | Sens | Message | Effet |
| --- | --- | --- | --- |
| `reservation.payment_succeeded` | payment → reservation | PaymentSucceeded | `type` RENTAL : PENDING → CONFIRMED ; DEPOSIT et EXTRA_FEE ignorés |
| `reservation.contract_closed` | contract → reservation | ContractClosed | → COMPLETED (CONFIRMED passe d'abord par ONGOING), puis publication de ReservationCompleted |
| `payment.reservation_cancelled` | reservation → payment | ReservationCancelled | publié dans la transaction de l'annulation |
| `ml.reservation_completed` | reservation → ml | ReservationCompleted | publié après le passage en COMPLETED (best effort) |

**Consommation** (`composer consume`) : les deux files sont déclarées au démarrage, durables, avec
`x-dead-letter-exchange = ""` et `x-dead-letter-routing-key = <file>.dlq` (mêmes arguments que payment-service), ainsi que
leur file `<file>.dlq`. Ack manuel, prefetch 1.

| Situation | Suite |
| --- | --- |
| Traité | ack ; l'`eventId` est gardé dans `processed_events` (même transaction que le changement de statut) |
| `eventId` déjà traité | ack, rien n'est refait |
| Enveloppe illisible, type inattendu, réservation inconnue, ContractClosed sur une réservation PENDING ou CANCELLED | directement dans `<file>.dlq` |
| ContractClosed sur une réservation déjà COMPLETED, paiement RENTAL sur une réservation déjà confirmée ou annulée | ack, ignoré (avertissement dans le log pour l'annulée) |
| Erreur passagère (base indisponible...) | une remise en file ; nouvel échec → `<file>.dlq` |

**Publication** : exchange par défaut, routing key = nom de la file, message persistant, `application/json`, `message_id` = `eventId`,
en-tête `X-Correlation-Id`, publisher confirms et `mandatory`. Pour ReservationCancelled, si RabbitMQ ou la file de payment-service
manquent, l'annulation est refusée (503) et rien ne change : aucun remboursement ne peut se perdre. ReservationCompleted n'est
envoyé qu'une fois la transaction validée ; un échec est seulement journalisé.

**Tester sans les autres services** : lancer `composer consume`, puis console http://localhost:15672 (guest / guest) → Queues and Streams
→ `reservation.contract_closed` → Publish message, `content_type` = `application/json`, Delivery mode 2, coller
`docs/events/ContractClosed.json` en remplaçant `reservationId` par `33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8` (r-confirmed) :
la réservation passe en COMPLETED, avec deux lignes d'historique (CONFIRMED → ONGOING → COMPLETED).
Même chose avec `docs/events/PaymentSucceeded.json` sur `reservation.payment_succeeded` et
`reservationId` = `01cb68ad-4eec-4bdd-8971-ce35381c59d1` (r-pending) : PENDING → CONFIRMED.

## Appels aux autres services

Choisis par `LOOKUP_MODE` :

- `demo` (par défaut) : utilisateurs et véhicules de `docs/demo-ids.md`, au format exact des vraies réponses, sans appel réseau ;
- `eureka` : `GET /internal/users/{id}` sur RENTAL-USER et `GET /internal/vehicles/{id}` sur RENTAL-VEHICLE, adresse trouvée dans
  Eureka (jamais d'URL en dur), token client credentials du client `reservation-service` gardé en cache jusqu'à 30 s avant son
  expiration, en-tête `X-Correlation-Id` retransmis, délais 3 s (connexion) et 5 s (total).

| Situation | Réponse |
| --- | --- |
| 404 du service appelé | 404 « Client introuvable » ou « Véhicule introuvable » |
| Absent d'Eureka, injoignable, délai dépassé, 502 / 503 / 504, Keycloak indisponible | 503 |
| JSON illisible, champ manquant, 401 / 403 après un nouvel essai avec un token neuf, autre code | 502 |

## Variables d'environnement

Dans `.env` (modèle versionné : `.env.example`, sans secret).

| Variable | Défaut | Rôle |
| --- | --- | --- |
| `SERVICE_PORT` | 8103 | Port annoncé à Eureka |
| `SERVICE_HOST` | host.docker.internal | Nom annoncé à Eureka |
| `DB_URL` | mysql://reservation_svc:reservation_pwd@127.0.0.1:3306/reservation_db | Base |
| `EUREKA_URL` | http://localhost:8761/eureka | Eureka |
| `KEYCLOAK_ISSUER` | http://localhost:8180/realms/location-voitures | Émetteur attendu dans les tokens |
| `KEYCLOAK_JWKS` | (vide : déduit de l'émetteur) | Adresse des clés publiques, si Keycloak est joint par une autre adresse |
| `KEYCLOAK_TOKEN_URL` | (vide : déduit de l'émetteur) | Adresse du token client credentials |
| `KEYCLOAK_CLIENT_ID` | reservation-service | Client Keycloak du service |
| `KEYCLOAK_CLIENT_SECRET` | (vide) | Secret de ce client, seulement dans `.env` |
| `RABBITMQ_URL` | amqp://guest:guest@localhost:5672 | RabbitMQ |
| `LOOKUP_MODE` | demo | `demo` ou `eureka` |

## Documentation OpenAPI

Générée par `darkaonline/l5-swagger` à partir des attributs PHP 8 (`#[OA\...]`) : les opérations sont décrites sur les
méthodes des contrôleurs, les schémas, paramètres et réponses d'erreur communs dans `app/OpenApi/`. Chaque route a un résumé,
ses paramètres, son corps, des exemples, et ses réponses de succès et d'erreur.

| | |
| --- | --- |
| Swagger UI | http://localhost:8103/api/doc (bouton « Authorize » : coller un access token) |
| Contrat JSON | http://localhost:8103/docs |
| Contrat partagé | `docs/openapi/reservation-service.yaml` |

Après un changement de route, de corps ou de réponse, régénérer le contrat partagé :

```
composer openapi:dump
```

Il écrit le fichier en UTF-8 sans BOM avec des fins de ligne LF (une redirection `>` sous PowerShell 5.1 écrirait de l'UTF-16).
Le test `OpenApiTest` échoue si le contrat partagé n'est plus à jour, ou si une route n'est pas documentée avec ses erreurs.
En développement, `L5_SWAGGER_GENERATE_ALWAYS=true` (dans `.env`) régénère la documentation à chaque affichage de Swagger UI.

## Pages Angular

Dans `frontend/src/app/features/reservations/`, chargées par la route `/reservations` (rôles CLIENT, AGENT, ADMIN), appels
`/api/reservations` via le proxy Angular et la Gateway :

| Page | Contenu |
| --- | --- |
| `/reservations` | « Mes réservations » pour un CLIENT, toutes les réservations pour un AGENT ou un ADMIN ; filtres (statut ; client et véhicule pour AGENT / ADMIN), pagination |
| `/reservations/new` | Réserver : véhicule, dates, agence de retour (et client pour AGENT / ADMIN) ; « Voir le prix » affiche le prix, la caution et le prix conseillé ; « Réserver » crée la réservation |
| `/reservations/:id` | Détail et historique ; annuler avec un motif (PENDING ou CONFIRMED) ; modifier les dates (PENDING) ; AGENT / ADMIN : confirmer, remettre le véhicule, terminer ; ADMIN : supprimer une réservation annulée |

Les erreurs du service (400, 409, 422, 503...) sont affichées avec leur message et leur détail par champ.
Tant que le module Véhicules n'est pas branché, la liste des véhicules du formulaire reprend ceux de `docs/demo-ids.md`
(un autre véhicule se choisit par son UUID).

```
cd frontend
npm ci
npm start           # http://localhost:4200, connexion Keycloak (client1 / client1_pwd)
```

## Tests

```
composer test
```

PHPUnit sur SQLite en mémoire (extension `pdo_sqlite`), sans Keycloak, Eureka ni RabbitMQ : les tokens sont signés par une clé de
test (`tests/Fixtures/jwt/`, sans lien avec Keycloak), les appels HTTP sont simulés (`Http::fake`), Eureka et RabbitMQ sont remplacés
par des doublures (`tests/Support/`). Les dates sont figées au 2026-11-09.

| Fichier | Couvre |
| --- | --- |
| `InternalReservationsTest` | Les 3 routes `/internal`, formats exacts, 401 / 403 / 404 |
| `JwtAuthenticationTest`, `KeycloakJwksProviderTest` | Signature, algorithme, émetteur, expiration, rôles, cache JWKS, Keycloak absent |
| `ReservationApiTest` | API publique, les 7 règles de création, transitions, droits par rôle |
| `EurekaLookupTest` | Mode eureka : appels réels simulés, token de service, 404 / 502 / 503 |
| `MessageProcessorTest` | PaymentSucceeded, ContractClosed (CONFIRMED et ONGOING → COMPLETED), doublons, DLQ, remise en file |
| `PublishedEventsTest` | ReservationCancelled et ReservationCompleted au format de `docs/events/`, annulation refusée sans RabbitMQ |
| `EurekaCommandTest` | Enregistrement, battements, réenregistrement, retrait à l'arrêt |
| `OpenApiTest` | Swagger UI, chaque route documentée avec ses erreurs, contrat partagé à jour |

Les pages Angular sont vérifiées par la compilation (`npx ng build` dans `frontend/`).

## Données de démonstration

`php artisan db:seed` (relançable) remet les réservations de `docs/demo-ids.md` dans leur état initial, avec leur historique :

| Clé | UUID | Client | Véhicule | Dates | Statut | totalPrice | depositAmount |
| --- | --- | --- | --- | --- | --- | --- | --- |
| r-pending | 01cb68ad-4eec-4bdd-8971-ce35381c59d1 | client1 | v1 | 2026-12-10 au 2026-12-13 | PENDING | 270.000 | 500.000 |
| r-confirmed | 33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8 | client1 | v2 | 2026-11-20 au 2026-11-25 | CONFIRMED | 450.000 | 500.000 |
| r-completed | 30eeff32-a413-42fb-9845-8ede4b7f98e0 | client2 | v3 | 2026-10-01 au 2026-10-05 | COMPLETED | 640.000 | 1000.000 |

Comptes Keycloak : `client1` / `client1_pwd`, `client2` / `client2_pwd`, `agent1` / `agent1_pwd`, `admin1` / `admin1_pwd`.

## Utilisation de l'IA

J'ai développé ce service avec un assistant de programmation IA, utilisé dans VS Code, comme le permet le module.

**Ce que l'assistant a fait**, étape par étape et à ma demande :

- lire les documents communs de l'équipe (`docs/conventions.md`, `docs/internal-api.md`, `docs/events/`, `docs/demo-ids.md`) et le code
  de complaint-service, puis proposer un plan en six étapes ;
- écrire le code (migrations, modèles, règles métier, sécurité JWT, Eureka, RabbitMQ, appels aux autres services), les tests
  PHPUnit, la documentation OpenAPI, les pages Angular et ce README ;
- lancer les tests et des démonstrations sur la vraie infrastructure (MySQL, Keycloak, Eureka, Gateway, RabbitMQ, conteneur de
  user-service), et me montrer les résultats à la fin de chaque étape ;
- relire le code des autres services (payment, contract, user, vehicle) pour vérifier que mes formats correspondent à ce qu'ils lisent.

**Ce que j'ai fait moi-même :**

- choisir l'ordre des étapes et valider chacune avant de passer à la suivante ;
- prendre les décisions de conception, en accord avec l'équipe : une réservation PENDING, CONFIRMED ou ONGOING bloque le véhicule ;
  le passage en ONGOING par un AGENT ou un ADMIN ; le passage par ONGOING à la réception de ContractClosed (proposition de Rania) ;
  le prix facturé égal au prix fixe, le prix du ML restant une information ;
- échanger avec les autres membres (Fadi, Montassar, Rania) sur les formats et tester leurs services avec le mien ;
- relire le code, les messages d'erreur et la documentation.

**Ce que la vérification a permis de corriger** (exemples) : les UUID v7 générés par défaut par Laravel au lieu des UUID v4 de la
convention ; les montants écrits `270.0` au lieu de `270.000` ; des 503 prévus qui écrivaient une trace d'erreur complète dans le log.
Le passage à Laravel 12 vient d'un blocage réel : Composer refuse toutes les versions de Laravel 11 (failles non corrigées).

**Limites** : l'assistant n'a modifié aucun document commun de l'équipe (les écarts trouvés m'ont été signalés), ni aucun fichier
hors de `services/reservation-service`, `frontend/src/app/features/reservations` et `docs/openapi/reservation-service.yaml`.
Aucun secret n'est versionné : le secret Keycloak du service reste dans `.env`. Les pages Angular ont été vérifiées par la compilation,
pas encore par des tests de bout en bout dans le navigateur.
