# user-service

Microservice des **utilisateurs**, de leurs **adresses** et de leurs **permis de conduire** pour la plateforme de location de voitures.
Responsable : Montassar.

| | |
|---|---|
| Framework | Spring Boot 3.3.13, Spring Cloud 2023.0.4, Java 17, Maven (wrapper `mvnw`) |
| Port | 8101 |
| Base | MySQL `user_db` (utilisateur `user_svc`), migrations Flyway |
| Nom Eureka | `RENTAL-USER` (annoncé sous `host.docker.internal`) |
| Routes Gateway | `/api/users/**`, `/api/licenses/**` |
| Swagger UI | http://localhost:8101/swagger-ui.html |
| OpenAPI | http://localhost:8101/v3/api-docs (copie dans [docs/openapi/user-service.yaml](../../docs/openapi/user-service.yaml)) |

## Lancer le service (Windows, PowerShell)

Prérequis : Java 17, Docker Desktop. L'infrastructure doit tourner (`infra/` : `docker compose up -d`).

```powershell
cd services\user-service
Copy-Item .env.example .env      # puis mettre le vrai KEYCLOAK_CLIENT_SECRET dans .env
.\mvnw.cmd spring-boot:run
```

Vérifier : http://localhost:8101/health doit répondre `{"status":"UP"}`, et `RENTAL-USER` doit apparaître sur http://localhost:8761.

Au démarrage, Flyway crée les tables (`V1__schema.sql`) et charge les données de démo (`V2__demo_data.sql`).
Ne jamais modifier une migration déjà appliquée : ajouter un fichier `V3__...sql`.

### Lancer dans Docker (démo finale)

Le service a son bloc `user-service` dans [infra/docker-compose.yml](../../infra/docker-compose.yml), sous le profil `services`
(`docker compose up -d` seul ne lance toujours que l'infrastructure). Le secret vient de `infra/.env` (`USER_KEYCLOAK_CLIENT_SECRET`, voir `infra/.env.example`).

```powershell
cd infra
docker compose --profile services up -d --build user-service   # construit l'image (Dockerfile multi-étapes) et la lance sur 8101
docker compose --profile services stop user-service             # l'arrête (libère le port 8101 pour mvnw)
```

Ne pas lancer en même temps `mvnw spring-boot:run` et le conteneur : les deux utilisent le port 8101.
Dans le conteneur : base `mysql:3306`, Eureka `eureka:8761`, clés et token Keycloak par `keycloak:8080`
(`KEYCLOAK_JWKS`, `KEYCLOAK_TOKEN_URL`) ; l'émetteur attendu reste `http://localhost:8180/realms/location-voitures`.
`USER_RESERVATIONS_MOCK=false` dans `infra/.env` active le vrai appel OpenFeign.

### Variables d'environnement (`.env`, non commité)

| Variable | Rôle | Valeur locale |
|---|---|---|
| `SERVICE_PORT` | port HTTP | `8101` |
| `SERVICE_HOST` | nom annoncé dans Eureka | `host.docker.internal` |
| `DB_URL`, `DB_USERNAME`, `DB_PASSWORD` | base MySQL | `jdbc:mysql://localhost:3306/user_db`, `user_svc`, `user_pwd` |
| `EUREKA_URL` | serveur Eureka | `http://localhost:8761/eureka` |
| `KEYCLOAK_ISSUER` | émetteur des tokens | `http://localhost:8180/realms/location-voitures` |
| `KEYCLOAK_JWKS`, `KEYCLOAK_TOKEN_URL` | facultatives : adresse des clés et du token (Docker : `keycloak:8080`) | absentes en local : déduites de `KEYCLOAK_ISSUER` |
| `KEYCLOAK_CLIENT_ID`, `KEYCLOAK_CLIENT_SECRET` | compte de service pour les appels `/internal` | `user-service`, secret dans Keycloak > Clients > user-service > Credentials |
| `RESERVATIONS_MOCK` | `true` : historique des réservations simulé ; `false` : vrai appel OpenFeign | `true` tant que la route d'Aziz n'est pas prête |

## Modèle de données

| Table | Champs | Règles |
|---|---|---|
| `users` | `id` (UUID = `sub` Keycloak), `firstname`, `lastname`, `email`, `phone`, `role` (CLIENT/AGENT/ADMIN), `active` | email unique |
| `addresses` | `id`, `street`, `city`, `zipCode`, `userId` | plusieurs adresses par utilisateur |
| `driver_licenses` | `id`, `number`, `category`, `issueDate`, `expiryDate`, `verified`, `userId` | numéro unique, **un permis par utilisateur** |

`userId` est un simple UUID (pas de relation JPA). Les clés étrangères ne relient que des tables de `user_db` ; supprimer un utilisateur supprime ses adresses et son permis.

Données de démo (UUID de [docs/demo-ids.md](../../docs/demo-ids.md), noms et emails du realm Keycloak) :

| Utilisateur | Rôle | Permis |
|---|---|---|
| client1 `f4d1bab3-…` | CLIENT | TN-2020-000111, valide jusqu'au 2030-03-15 |
| client2 `611f32de-…` | CLIENT | TN-2016-000222, **expiré le 2026-06-30** |
| agent1 `11c9b774-…` | AGENT | aucun |
| admin1 `fd797399-…` | ADMIN | aucun |

## Routes

| Méthode | Route | Rôles | Succès |
|---|---|---|---|
| GET | `/health` | public | 200 |
| GET | `/api/users?role=&name=&page=0&size=20` | ADMIN, AGENT | 200 (page) |
| GET | `/api/users/me` | connecté | 200 |
| GET | `/api/users/{id}` | ADMIN, AGENT, lui-même | 200 |
| POST | `/api/users` | ADMIN ; ou soi-même en CLIENT | 201 + Location |
| PUT | `/api/users/{id}` | ADMIN, lui-même (sans changer rôle/statut) | 200 |
| DELETE | `/api/users/{id}` | ADMIN | 204 |
| GET | `/api/users/{id}/reservations?page=&size=` | ADMIN, AGENT, lui-même | 200 (page) |
| GET/POST | `/api/users/{userId}/addresses` | lecture : ADMIN, AGENT, lui-même ; écriture : ADMIN, lui-même | 200 / 201 |
| GET/PUT/DELETE | `/api/users/{userId}/addresses/{addressId}` | idem | 200 / 200 / 204 |
| GET | `/api/licenses?userId=&page=&size=` | ADMIN, AGENT | 200 (page) |
| GET | `/api/licenses/{id}` | ADMIN, AGENT, propriétaire | 200 |
| POST | `/api/licenses` | ADMIN, propriétaire | 201 + Location |
| PUT | `/api/licenses/{id}` | ADMIN, propriétaire | 200 |
| PATCH | `/api/licenses/{id}/verify` | ADMIN, AGENT | 200 |
| DELETE | `/api/licenses/{id}` | ADMIN, propriétaire | 204 |
| GET | `/internal/users/{id}` | INTERNAL (comptes de service) | 200 |

`/internal/users/{id}` (utilisé par reservation-service) renvoie le format de [docs/internal-api.md](../../docs/internal-api.md) :
`{id, firstname, lastname, email, phone, role, active, license: {number, category, issueDate, expiryDate, verified} ou null}`.
La Gateway bloque `/internal/**` ; les autres services appellent `RENTAL-USER` via Eureka.

## Règles métier et codes HTTP

Toutes les erreurs suivent le format commun `{"status","error","message","details":[{"field","message"}]}`.

| Cas | Code |
|---|---|
| JSON illisible, UUID mal formé, champ obligatoire absent, pagination invalide | 400 |
| Token absent ou invalide | 401 |
| Rôle insuffisant, ressource d'un autre utilisateur, non-ADMIN qui change un rôle ou un statut | 403 |
| Utilisateur, adresse ou permis introuvable | 404 |
| Email déjà utilisé, numéro de permis déjà enregistré, utilisateur ayant déjà un permis, id déjà existant | 409 |
| Email ou téléphone au mauvais format, code postal ≠ 4 chiffres, catégorie de permis inconnue | 422 |
| Permis **déjà expiré à la création**, délivrance dans le futur, expiration avant délivrance, vérification d'un permis expiré | 422 |

Formats : email avec domaine (`nom@domaine.tn`) ; téléphone de 8 à 15 chiffres avec `+` facultatif (`+21620111111`).
Toute modification d'un permis remet `verified` à `false`.

## Sécurité (Keycloak)

- Resource server JWT, émetteur `KEYCLOAK_ISSUER`. Les rôles sont lus dans la claim **`roles`** du token (`KeycloakRolesConverter` → `ROLE_CLIENT`, `ROLE_ADMIN`…).
- L'utilisateur connecté est identifié par la claim `sub`, qui est aussi l'`id` de `users`.
- Règles globales dans `SecurityConfig` (`/internal/**` → INTERNAL) ; règles fines par route avec `@PreAuthorize` et le bean `AccessGuard` (`@access.isSelf(#id)`…).

Obtenir un token pour tester (PowerShell) :

```powershell
$token = (Invoke-RestMethod -Method Post -Uri http://localhost:8180/realms/location-voitures/protocol/openid-connect/token `
  -Body @{ grant_type='password'; client_id='rental-front'; username='admin1'; password='admin1_pwd' }).access_token
Invoke-RestMethod -Uri "http://localhost:8101/api/users?role=CLIENT" -Headers @{ Authorization = "Bearer $token" }
```

Dans Swagger UI : bouton **Authorize**, coller le token (sans « Bearer »).
Token de service (rôle INTERNAL) : `grant_type=client_credentials`, `client_id=<service>`, `client_secret=<secret>`.

## OpenFeign : historique des réservations (démo du workshop)

`GET /api/users/{id}/reservations` appelle reservation-service :

- `ReservationClient` : `@FeignClient(name = "RENTAL-RESERVATION")` → `GET /internal/reservations?userId=&page=&size=`. L'adresse est trouvée dans Eureka, jamais d'URL en dur.
- `ReservationFeignConfig` : ajoute le token du compte de service `user-service` (client credentials, rôle INTERNAL) et retransmet `X-Correlation-Id`.
- `ReservationDto` : `{id, userId, vehicleId, pickupAgencyId, returnAgencyId, startDate, endDate, status, totalPrice, depositAmount, currency}`.
- `FeignErrorHandler` (`@RestControllerAdvice`) traduit les erreurs :

| Exception Feign | Situation | Réponse |
|---|---|---|
| `FeignException.NotFound` | l'autre service répond 404 | 404 |
| `RetryableException` | service arrêté, connexion refusée, délai dépassé | 503 |
| `FeignException.ServiceUnavailable` | aucune instance `RENTAL-RESERVATION` dans Eureka | 503 |
| `DecodeException` | réponse illisible | 502 |
| autre `FeignException` | erreur inattendue de l'autre service | 502 |

Tant que la route d'Aziz n'est pas prête, `RESERVATIONS_MOCK=true` active `MockReservationHistory`, qui renvoie les réservations de `docs/demo-ids.md` (client1 : 2, client2 : 1).
Pour la démo réelle : `RESERVATIONS_MOCK=false` dans `.env`, redémarrer. Sans reservation-service lancé, la route renvoie 503.

## Tests

```powershell
.\mvnw.cmd test                                   # tous les tests (61)
.\mvnw.cmd test -Dtest=LicenseApiTest             # une classe
.\mvnw.cmd test '-Dtest=UserApiTest$Creation'    # un groupe @Nested
```

Les tests utilisent H2 en mode MySQL (profil `test`) avec les mêmes migrations Flyway : ni Docker, ni Keycloak, ni Eureka ne sont nécessaires.
Les tokens sont simulés (`spring-security-test`, `jwt()`), un par rôle. Couverture : CRUD complet, 400/401/403/404/409/422, format `/internal`, pagination et filtres, traduction des erreurs Feign (404/503/502).

## Structure

```
src/main/java/com/location/userservice/
  common/       format d'erreur, pagination, gestion des erreurs, Swagger, /health
  security/     SecurityConfig, KeycloakRolesConverter, AccessGuard
  user/         User, UserService, UserController (/api/users), InternalUserController (/internal/users)
  address/      Address, AddressService, AddressController (/api/users/{userId}/addresses)
  license/      DriverLicense, LicenseService, LicenseController (/api/licenses)
  reservation/  ReservationClient (Feign), simulation, token de service, FeignErrorHandler
src/main/resources/db/migration/   V1__schema.sql, V2__demo_data.sql
```

## Pages Angular (`frontend/src/app/features/users/`)

| Route | Accès | Contenu |
|---|---|---|
| `/users` | tous | redirige vers `/users/admin` (ADMIN, AGENT) ou `/users/me` (CLIENT) |
| `/users/me` | connecté | **Mon profil** : informations (modifiables), permis (ajout, modification, suppression), adresses, historique des réservations. Si le profil n'existe pas encore, formulaire de création pré-rempli depuis Keycloak |
| `/users/admin` | ADMIN, AGENT | liste filtrée (rôle, nom) et paginée ; ADMIN : créer, modifier, supprimer |
| `/users/admin/:id` | ADMIN, AGENT | fiche : profil, permis (bouton « Marquer comme vérifié »), adresses, réservations |
| `/users/admin/new`, `/users/admin/:id/edit` | ADMIN | formulaire de création / modification |

Les erreurs du service (409, 422…) s'affichent sous les champs concernés grâce à `details[].field`.
Lancer : dans `frontend/`, `npm install` puis `npm start`, ouvrir http://localhost:4200 et se connecter avec `client1` / `client1_pwd` ou `admin1` / `admin1_pwd`.
Angular 21 demande Node 20.19+, 22.12+ ou 24 (pas Node 26).

## Usage de l'IA

Ce service a été développé avec l'aide de **Claude Code** (assistant IA d'Anthropic, modèle Claude Opus), utilisé dans VS Code.

- **Ce que l'IA a fait** : installation guidée des outils sous Windows, génération du `pom.xml` (Spring Boot 3.3.13 sur le modèle de `infra/eureka-server`), des migrations, des entités, des services, des contrôleurs, de la documentation Swagger, du client OpenFeign, de la sécurité Keycloak, des tests, des pages Angular (`features/users`) et de ce README. Elle a aussi lancé les vérifications de bout en bout (MySQL, tokens Keycloak réels, enregistrement Eureka, passage par la Gateway).
- **Ce que j'ai fourni et vérifié** : le cahier des charges (entités, règles, ports, conventions de l'équipe), la relecture du code, l'exécution des tests et des scénarios dans Swagger.
- **Choix faits avec l'IA** à partir des documents de l'équipe : `/api/users/me` pour la page « Mon profil » ; un seul permis par utilisateur (puisque `/internal/users/{id}` renvoie un `license` ou `null`) ; la route `PATCH /api/licenses/{id}/verify` pour les agents ; la distinction 400 (champ absent) / 422 (champ au mauvais format) ; les téléphones, adresses et numéros de permis des données de démo.
