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
composer serve                                               # symfony server:start --port=8106 --no-tls --allow-all-ip (composer serve:php = php -S 0.0.0.0:8106)
composer eureka                                              # app:eureka:run: register RENTAL-COMPLAINT, heartbeat every 30 s, Ctrl+C deregisters
php bin/console app:eureka:deregister                        # remove a stale instance (runner killed without Ctrl+C)
composer test                                                # recreate complaint_db_test + migrations + fixtures, then the whole suite
composer test:db                                             # only recreate the test database
php bin/phpunit --filter ChangeStatusTest                    # single class/test (after composer test:db)
```

Tests:
- PHPUnit fails on deprecations, notices and warnings (`phpunit.dist.xml`).
- The test DB is `complaint_db_test`: `.env.test` sets `DATABASE_URL`, because `.env.local` isn't loaded in test, and Doctrine adds the `_test` suffix.
- dama/doctrine-test-bundle wraps each test in a transaction that is rolled back, so every test starts from the fixtures. Never rely on test order; the suite passes with `--order-by=random`.
- Functional tests extend `tests/Functional/ApiTestCase.php`. Use `request($method, $uri, self::CLIENT2, $body)` and `assertError($status, 'CONFLICT', 'message part', ['field'])`. The dev authenticator is also active under `when@test`.
- `complaint_svc` needs rights on `complaint_db%`: they are in `infra/mysql/init.sql`, and `scripts/grant-test-db.sql` grants them on an existing volume.

API docs: Swagger UI at http://localhost:8106/api/doc, JSON at `/api/doc.json` (both public). After changing a route, a DTO or `config/packages/nelmio_api_doc.yaml`, regenerate the shared contract with `composer openapi:dump`, which writes `../../docs/openapi/complaint-service.yaml` in UTF-8 without BOM, with LF. Never use `nelmio:apidoc:dump > file` in PowerShell 5.1, because it writes UTF-16.

Inspect the database: `docker exec location-voitures-mysql-1 mysql -ucomplaint_svc -pcomplaint_pwd complaint_db -e "SELECT ..."`

PowerShell 5.1 mangles double quotes inside native-command arguments, so `php -r '... "x" ...'` breaks; put throwaway PHP in a script file instead.

## Configuration

The root `.gitignore` ignores `.env`, so this service versions `.env.dist` (Symfony loads it when `.env` is absent). Local overrides go in `.env.local` (ignored). Env vars follow the conventions: `SERVICE_PORT`, `SERVICE_HOST=host.docker.internal`, `DATABASE_URL`, `EUREKA_URL`, `KEYCLOAK_ISSUER`, `KEYCLOAK_CLIENT_ID=complaint-service`, `KEYCLOAK_CLIENT_SECRET` (in `infra/keycloak/location-voitures-realm.json`), `RABBITMQ_URL`.

## Domain model

- `Complaint` is the aggregate root. It owns `ComplaintMessage` and `ComplaintStatusHistory` (cascade persist, `onDelete: CASCADE`). State changes go through its methods (`changeStatus()`, `addMessage()`, `update()`), which also maintain `updatedAt`. Don't add setters for status.
- Messages and history entries have a per-complaint `position` (1, 2, ...), assigned by `Complaint`, unique per complaint and used for ordering. MySQL `DATETIME` has only one-second precision, so `createdAt` / `changedAt` can tie.
- Route ids accept upper and lower case (custom `UUID` pattern in `ComplaintController`; `Requirement::UUID` is lowercase-only). Every UUID is lowercased before any query or storage.
- A complaint references a reservation and/or a contract (`reservationId`, `contractId`) from other services by UUID only; there are no foreign keys across services.
- IDs (own and foreign) are UUID v4 strings stored as `CHAR(36)` (Doctrine `guid`), generated with `symfony/uid`. The constructor accepts a fixed `id` so fixtures can reuse the UUIDs from `docs/demo-ids.md`. Timestamps are `DateTimeImmutable` in UTC.
- Enums in `src/Enum/` (backed strings, mapped with `enumType`): `ComplaintCategory`, `ComplaintPriority`, `ComplaintStatus`, `AuthorRole`.
- **Status matrix** (team rule, in `ComplaintStatus::allowedTransitions()`): OPEN → IN_PROGRESS | REJECTED; IN_PROGRESS → RESOLVED | REJECTED | OPEN; RESOLVED and REJECTED are final. A forbidden transition throws `App\Exception\InvalidStatusTransition`, which must map to **409**. The constructor writes the first history entry (`null` → OPEN). In a final state, adding a message or changing the priority is also a 409.
- Priority: optional at creation (default MEDIUM), changed only by AGENT/ADMIN through `PATCH /{id}/priority`. It updates `updatedAt` but is not recorded in the status history.

## HTTP layer

- Request flow: `ComplaintController` (thin) → `ComplaintService::get()` (404) → `denyAccessUnlessGranted()` with `ComplaintVoter` (403, "who") → `ComplaintService` (409 state rules, 422 business rules, 404/403 reservation or contract ownership). JSON is built by hand in `ComplaintPresenter`, not with the serializer.
- Request bodies are DTOs in `src/Dto/` bound with `#[MapRequestPayload(validationFailedStatusCode: 400)]`, so body validation (400) runs before the controller, and therefore before the 404. This order is documented in Swagger. DTO conventions:
  - Properties are public `mixed` + `Assert\Type`, so a type error doesn't stop the other fields from being validated.
  - A required field has **no default value**: the validator reads an uninitialized property as null, so `NotBlank` gives 400, and Nelmio marks the field `required`. An optional field defaults to `null`.
  - Enum fields are strings checked with `Assert\Choice(callback: [Enum::class, 'values'])`; Nelmio turns that into `enum`.
  - Text fields use `NotBlank(normalizer: [Normalize::class, 'trim'])`.
  - Each property carries an `#[OA\Property]` (type, example). Nelmio generates the request-body schemas from the DTOs.
- Query parameters are parsed by `ComplaintFilter::fromRequest()`.
- OpenAPI: response schemas (`ComplaintSummary`, `ComplaintDetail`, `ComplaintPage`, `Error`...), shared error responses (`#/components/responses/NotFound`...) and the security schemes (`bearerAuth`, plus `devUserId` / `devRoles` for dev) live in `config/packages/nelmio_api_doc.yaml`. Operations are described with `#[OA\Get]`, `#[OA\Post]`... on the controller methods. Swagger UI assets are served locally (`html_config.assets_mode: bundle`).
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
- Service discovery uses the shared client in `../../shared/php-eureka` (namespace `Rental\Eureka`, wired through `composer.json` PSR-4 autoload, not a package). `Rental\Eureka\EurekaClient` is a service configured in `config/services.yaml`; the env vars have defaults there. Registration runs as the long-lived command `app:eureka:run` (`src/Command/EurekaRunCommand.php`) next to the web server. It does not use the shared `EurekaRunner`, which loops forever with no stop hook. Its own loop does the same thing, with 1 s ticks, and deregisters on Ctrl+C / CTRL_BREAK (`sapi_windows_set_ctrl_handler`) or SIGINT / SIGTERM (pcntl). Eureka runs with self-preservation on, so an instance killed without deregistering can linger.
- The server must listen on all interfaces (`composer serve`): the Gateway container reaches the host through `host.docker.internal`. Expect a few seconds of 500s from the Gateway after the service stops, until its instance cache refreshes; after that it returns 503. Internal calls carry a client-credentials token from Keycloak client `complaint-service` (role INTERNAL).
- Auth: Keycloak realm `location-voitures`, roles in the top-level `roles` claim. CLIENT sees and creates only its own complaints; AGENT and ADMIN process them. `/internal/**` is never exposed through the Gateway. `GET /health` → `{"status":"UP"}`.

## Demo data

Fixtures use the fixed UUIDs from `docs/demo-ids.md`: users client1, client2, agent1, admin1 (Keycloak IDs, password `<name>_pwd`), reservations r-pending, r-confirmed, r-completed, contract c-completed, and complaints rec-1 (client2, r-completed, BILLING, OPEN) and rec-2 (client2, r-completed, VEHICLE, RESOLVED).
