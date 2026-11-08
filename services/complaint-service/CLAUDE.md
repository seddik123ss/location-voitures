# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Scope

This is `complaint-service` (réclamations), one service of the car-rental microservices monorepo `location-voitures`. Owner: Seddik, branch `feature/complaint-service`. Work only inside this directory, plus `frontend/src/app/features/complaints/`. Docs, commit messages and user-facing strings are in **French**.

- Symfony 7.4, PHP 8.5, Doctrine ORM 3, MySQL `complaint_db`
- Port **8106**, Eureka name **RENTAL-COMPLAINT**, public routes `/api/complaints/**` (Gateway 8088 → `lb://RENTAL-COMPLAINT`)
- Dev machine is Windows + PowerShell 5.1

Cross-service contracts live in the repo root `docs/` and must be followed: `conventions.md` (JSON camelCase, pagination `{items, page, size, total}`, error format `{status, error, message, details}`, HTTP codes, env var names), `internal-api.md`, `demo-ids.md`, `events/README.md`. The PR checklist is `.github/pull_request_template.md`.

## Commands

Run from `services/complaint-service/`. Infra (MySQL, Keycloak, Eureka, Gateway, RabbitMQ) must be up: `docker compose up -d` in `infra/`.

```
composer install
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:fixtures:load --no-interaction     # purges complaint_db, reloads demo data
php bin/console doctrine:schema:validate
php bin/console doctrine:migrations:diff                    # after changing an entity
php -S 0.0.0.0:8106 -t public
php bin/phpunit                                              # all tests
php bin/phpunit --filter testName                            # single test
```

PHPUnit fails on deprecations, notices and warnings (`phpunit.dist.xml`).

Inspect the database: `docker exec location-voitures-mysql-1 mysql -ucomplaint_svc -pcomplaint_pwd complaint_db -e "SELECT ..."`

PowerShell 5.1 mangles double quotes inside native-command arguments, so `php -r '... "x" ...'` breaks; put throwaway PHP in a script file instead.

## Configuration

The root `.gitignore` ignores `.env`, so this service versions `.env.dist` (Symfony loads it when `.env` is absent). Local overrides go in `.env.local` (ignored). Env vars follow the conventions: `SERVICE_PORT`, `SERVICE_HOST=host.docker.internal`, `DATABASE_URL`, `EUREKA_URL`, `KEYCLOAK_ISSUER`, `KEYCLOAK_CLIENT_ID=complaint-service`, `KEYCLOAK_CLIENT_SECRET` (in `infra/keycloak/location-voitures-realm.json`), `RABBITMQ_URL`.

## Domain model

- `Complaint` is the aggregate root. It owns `ComplaintMessage` and `ComplaintStatusHistory` (cascade persist, `onDelete: CASCADE`). State changes go through its methods (`changeStatus()`, `addMessage()`, `update()`), which also maintain `updatedAt`. Don't add setters for status.
- A complaint references a reservation and/or a contract (`reservationId`, `contractId`) from other services by UUID only; there are no foreign keys across services.
- IDs (own and foreign) are UUID v4 strings stored as `CHAR(36)` (Doctrine `guid`), generated with `symfony/uid`. The constructor accepts a fixed `id` so fixtures can reuse the UUIDs from `docs/demo-ids.md`. Timestamps are `DateTimeImmutable` in UTC.
- Enums in `src/Enum/` (backed strings, mapped with `enumType`): `ComplaintCategory`, `ComplaintPriority`, `ComplaintStatus`, `AuthorRole`.
- **Status matrix** (team rule, in `ComplaintStatus::allowedTransitions()`): OPEN → IN_PROGRESS | REJECTED; IN_PROGRESS → RESOLVED | REJECTED | OPEN; RESOLVED and REJECTED are final. A forbidden transition throws `App\Exception\InvalidStatusTransition`, which must map to **409**. The constructor writes the first history entry (`null` → OPEN).

## HTTP layer

- Request flow: `ComplaintController` (thin) → `ComplaintService::get()` (404) → `denyAccessUnlessGranted()` with `ComplaintVoter` (403, "who") → `ComplaintService` (409 state rules, 422 business rules, 404/403 reservation or contract ownership). JSON is built by hand in `ComplaintPresenter`, not with the serializer.
- Request bodies are DTOs in `src/Dto/` bound with `#[MapRequestPayload(validationFailedStatusCode: 400)]`. Properties are `mixed` + `Assert\Type`, so a type error doesn't stop the other fields from being validated. Enum fields are strings checked with `Assert\Choice(callback: [Enum::class, 'values'])`. Query parameters are parsed by `ComplaintFilter::fromRequest()`.
- Every error goes through `App\EventListener\ApiExceptionListener` → `ApiResponse::error()`. Throw `App\Exception\ApiException` (factories `notFound`, `forbidden`, `conflict`, `unprocessable`, `badGateway`, `serviceUnavailable`), never build an error response in a controller.
- Responses use `ApiResponse::json()` (unescaped UTF-8). Instants are formatted `Y-m-d\TH:i:s\Z`, and `Kernel::boot()` forces the UTC timezone.
- Caller = `App\Security\ApiUser` (id + realm roles), injected with `#[CurrentUser]`. In dev it comes from `DevHeaderAuthenticator` (headers `X-Dev-User-Id: <uuid>`, `X-Dev-Roles: CLIENT|AGENT|ADMIN`), registered only under `when@dev` in `security.yaml`. In other environments there is no authenticator yet, so `/api/**` returns 401 until the JWT authenticator replaces it.

Example (Git Bash; PowerShell's `curl` is an alias for `Invoke-WebRequest`):
```
curl -s http://127.0.0.1:8106/api/complaints -H "X-Dev-User-Id: 611f32de-ce7b-4927-b1c4-d113b6479d59" -H "X-Dev-Roles: CLIENT"
```
On Windows, send JSON bodies containing accents from a file (`--data-binary @file.json`): `curl.exe` arguments are not passed as UTF-8.

## Integration with other services

- Ownership checks call `GET /internal/reservations/{id}` (RENTAL-RESERVATION) and `GET /internal/contracts/{id}` (RENTAL-CONTRACT): not found → 404, belongs to another user → 403. Peer/Eureka down → 503, unreadable response → 502. This goes through the interfaces `App\Integration\ReservationLookup` / `ContractLookup` (return `null` when not found). Their only implementations for now are `App\Integration\Demo\Demo*Lookup` (built from `DemoIds`, autowired automatically). The Eureka + HTTP version will replace them without changing `ComplaintService`.
- Service discovery uses the shared client in `../../shared/php-eureka` (namespace `Rental\Eureka`, wired through `composer.json` PSR-4 autoload, not a package). Registration runs as the long-lived console command `app:eureka:run` next to the web server. Internal calls carry a client-credentials token from Keycloak client `complaint-service` (role INTERNAL).
- Auth: Keycloak realm `location-voitures`, roles in the top-level `roles` claim. CLIENT sees and creates only its own complaints; AGENT and ADMIN process them. `/internal/**` is never exposed through the Gateway. `GET /health` → `{"status":"UP"}`.

## Demo data

Fixtures use the fixed UUIDs from `docs/demo-ids.md`: users client1, client2, agent1, admin1 (Keycloak IDs, password `<name>_pwd`), reservations r-pending, r-confirmed, r-completed, contract c-completed, and complaints rec-1 (client2, r-completed, BILLING, OPEN) and rec-2 (client2, r-completed, VEHICLE, RESOLVED).
