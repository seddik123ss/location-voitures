# loyalty-service

Fidélité et codes promo de la plateforme de location de voitures.
Spring Boot 3.3.13 + Spring Cloud 2023.0.4, Java 17 · port **8108** · base MySQL **loyalty_db** · nom Eureka **RENTAL-LOYALTY**.

- Chaque **client** a un compte de fidélité, ouvert automatiquement à la création de son profil (message `UserCreated`), avec **100 points** de bienvenue.
- Il **gagne des points** à chaque location terminée (message `ReservationCompleted`) et monte de niveau : **BRONZE → SILVER → GOLD**.
- Il **convertit ses points** en bon de réduction personnel `LOY-XXXXXX`.
- L'**ADMIN** crée des **codes promo** ; le client les saisit en réservant ; reservation-service les **valide** puis les **applique** (`/internal`).

Toutes les briques de l'infrastructure sont utilisées : Gateway (`/api/loyalty/**`, `/api/promo-codes/**`), Eureka, Keycloak (resource server + compte de service INTERNAL), RabbitMQ (consommateur), OpenFeign (vers user-service et reservation-service).

```
Angular :4200 ──► Gateway :8088 ──(token Keycloak)──► RENTAL-LOYALTY :8108 (adresse trouvée via Eureka)
                                                          │ OpenFeign (token de service INTERNAL)
                                                          ├──► RENTAL-USER         GET /internal/users/{id}
                                                          └──► RENTAL-RESERVATION  GET /internal/reservations/{id}
user-service        ── UserCreated / UserDeleted ──► RabbitMQ loyalty.user_events          ──► ouvre / ferme le compte
reservation-service ── ReservationCompleted      ──► RabbitMQ loyalty.reservation_completed ──► ajoute des points
reservation-service ── OpenFeign ──► /internal/promo-codes/validate puis /apply
```

## Lancer le service (Windows, PowerShell)

Prérequis : JDK 17 à 23 (21 conseillé), Docker Desktop avec l'infrastructure lancée (`cd infra ; docker compose up -d`).

```powershell
cd services\loyalty-service
copy .env.example .env      # puis KEYCLOAK_CLIENT_SECRET = secret du client loyalty-service (realm ou infra/.env.example)
.\mvnw.cmd spring-boot:run  # Flyway crée les tables et les données de démo au premier démarrage
```

- Santé : http://localhost:8108/health → `{"status":"UP"}`
- Swagger : http://localhost:8108/swagger-ui.html (bouton **Authorize** : coller un token Keycloak)
- Eureka : http://localhost:8761 → **RENTAL-LOYALTY** UP
- RabbitMQ : http://localhost:15672 → files `loyalty.user_events` et `loyalty.reservation_completed` (1 consommateur chacune) et leurs `.dlq`

**Base existante** : `infra/mysql/init.sql` ne s'exécute que sur un volume MySQL neuf. Sur une installation déjà en place, créer la base une fois :
```powershell
cd infra
"CREATE DATABASE IF NOT EXISTS loyalty_db; CREATE USER IF NOT EXISTS 'loyalty_svc'@'%' IDENTIFIED BY 'loyalty_pwd'; GRANT ALL PRIVILEGES ON loyalty_db.* TO 'loyalty_svc'@'%'; FLUSH PRIVILEGES;" | docker compose exec -T mysql sh -c 'mysql -uroot -p$MYSQL_ROOT_PASSWORD'
```
De même, le client Keycloak `loyalty-service` est dans `infra/keycloak/location-voitures-realm.json`, importé seulement sur un volume Keycloak neuf : sinon, le créer dans la console (Clients → Create, *Client authentication* et *Service accounts* activés, même secret) et donner le rôle **INTERNAL** à son compte de service.

Obtenir un token pour Swagger :
```powershell
$r = Invoke-RestMethod -Method Post http://localhost:8180/realms/location-voitures/protocol/openid-connect/token `
  -Body @{ grant_type='password'; client_id='rental-front'; username='client1'; password='client1_pwd' }
$r.access_token
```

### Lancer dans Docker (démo finale)

```powershell
cd infra
docker compose --profile services up -d --build loyalty-service
```
Secret lu dans `infra/.env` : `LOYALTY_KEYCLOAK_CLIENT_SECRET` (valeur de dev dans `infra/.env.example`).

### Variables d'environnement (`.env`, non commité)

| Variable | Défaut | Rôle |
| --- | --- | --- |
| `SERVICE_PORT` | 8108 | port HTTP |
| `SERVICE_HOST` | host.docker.internal | nom annoncé dans Eureka (jamais d'IP) |
| `DB_URL`, `DB_USERNAME`, `DB_PASSWORD` | `jdbc:mysql://localhost:3306/loyalty_db`, loyalty_svc, loyalty_pwd | base MySQL |
| `EUREKA_URL` | http://localhost:8761/eureka | serveur Eureka |
| `KEYCLOAK_ISSUER` | http://localhost:8180/realms/location-voitures | émetteur des tokens |
| `KEYCLOAK_JWKS`, `KEYCLOAK_TOKEN_URL` | déduites de l'émetteur | à renseigner dans Docker (keycloak:8080) |
| `KEYCLOAK_CLIENT_ID`, `KEYCLOAK_CLIENT_SECRET` | loyalty-service, vide | token de service pour OpenFeign |
| `RABBITMQ_URL` | amqp://guest:guest@localhost:5672 | broker |

## Modèle de données (Flyway `V1__schema.sql`, `V2__demo_data.sql`, `ddl-auto: validate`)

| Table | Contenu | Contraintes notables |
| --- | --- | --- |
| `loyalty_accounts` | userId (PK = sub Keycloak), balance, lifetimePoints, tier, status | balance ≥ 0, tier et status en CHECK |
| `point_transactions` | WELCOME, EARN, REDEEM, ADJUST ; points ±, motif, reservationId | **UNIQUE (type, reservation_id)** : une réservation ne rapporte qu'une fois |
| `promo_codes` | code, PERCENT/AMOUNT, valeur, minAmount, minTier, validité, maxUses, maxUsesPerUser, usedCount, ownerUserId | code UNIQUE ; PERCENT ≤ 50 ; validUntil > validFrom ; usedCount ≤ maxUses |
| `promo_usages` | code utilisé, client, réservation, montants avant / réduction / après | **reservation_id UNIQUE** : apply idempotent |
| `processed_events` | eventId des messages RabbitMQ traités | PK : idempotence du consommateur |

UUID en `CHAR(36)`, montants en `DECIMAL(12,3)` (TND), dates en UTC. Aucune clé étrangère vers une autre base.

## Règles de calcul

| Règle | Détail |
| --- | --- |
| Bienvenue | 100 points à l'ouverture (WELCOME) |
| Points gagnés | `floor(totalPrice / 10)` × coefficient du niveau (BRONZE ×1, SILVER ×1.25, GOLD ×1.5), arrondi à l'inférieur. 640 TND en SILVER → 64 × 1.25 = **80** |
| Niveau | sur le **cumul** (`lifetimePoints`), jamais sur le solde : BRONZE 0–499, SILVER 500–1499, GOLD ≥ 1500. Dépenser ne fait jamais descendre |
| Conversion | multiples de 100 ; 100 points = 10.000 TND ; bon `LOY-XXXXXX` (AMOUNT, 1 utilisation, 90 jours, réservé au client) |
| Réduction | PERCENT de 1 à 50 %, AMOUNT > 0 ; jamais plus que le montant (`amountAfter ≥ 0.000`) ; arrondi à 3 décimales |
| Ajustement ADMIN | positif : geste commercial (compte aussi dans le cumul) ; négatif : correction (solde seulement, jamais sous 0) ; motif obligatoire |

## Routes

Publiques (via la Gateway, token Keycloak) :

| Route | Rôles | Description |
| --- | --- | --- |
| `GET /api/loyalty/me` | CLIENT | solde, cumul, niveau, `nextTier`, `pointsToNextTier`, `progress` (0 à 1) |
| `GET /api/loyalty/me/transactions?page&size` | CLIENT | historique paginé, du plus récent au plus ancien |
| `POST /api/loyalty/me/redeem {points}` | CLIENT | conversion → **201** + le bon `LOY-…` |
| `GET /api/loyalty/me/vouchers` | CLIENT | mes bons non utilisés et non expirés |
| `GET /api/loyalty/accounts?tier&page&size` | AGENT, ADMIN | comptes, filtre par niveau |
| `GET /api/loyalty/accounts/{userId}` | AGENT, ADMIN, propriétaire | détail |
| `POST /api/loyalty/accounts/{userId}/adjust {points, reason}` | ADMIN | ajustement (ADJUST) |
| `GET /api/promo-codes?active&page&size` | AGENT, ADMIN | codes promo (sans les bons personnels), avec `state` calculé : VALID, UPCOMING, EXPIRED, EXHAUSTED, INACTIVE |
| `GET /api/promo-codes/{id}` | AGENT, ADMIN | détail et `usedCount` |
| `POST /api/promo-codes` | ADMIN | création → **201** + `Location` (code mis en majuscules) |
| `PUT /api/promo-codes/{id}` | ADMIN | modification (`active=false` pour désactiver) |
| `DELETE /api/promo-codes/{id}` | ADMIN | **204** si jamais utilisé, sinon **409** (le désactiver) |
| `POST /api/promo-codes/check {code, amount}` | CLIENT | aperçu `{code, amountBefore, discount, amountAfter}`, rien n'est consommé |

Internes (rôle **INTERNAL**, jamais routées par la Gateway) :

| Route | Appelée par | Description |
| --- | --- | --- |
| `POST /internal/promo-codes/validate {code, userId, amount}` | reservation | 200 `{code, amountBefore, discount, amountAfter}` ou erreur ; vérifie aussi l'utilisateur dans user-service ; ne consomme rien |
| `POST /internal/promo-codes/apply {code, userId, reservationId, amount}` | reservation | revalide puis enregistre → **201** ; même `reservationId` et même code → **200** avec la même utilisation ; autre code sur la même réservation → 409 |
| `GET /internal/loyalty/{userId}` | tout service | `{userId, tier, balance, status}` |

Contrat complet : `docs/openapi/loyalty-service.yaml` (exporté depuis `/v3/api-docs.yaml`).

## Règles métier et codes HTTP

Ordre des contrôles d'un code (le premier qui échoue décide), format d'erreur commun `{status, error, message, details[{field, message}]}` :

| Cas | Code | field |
| --- | --- | --- |
| Code promo inconnu | 404 | code |
| Code inactif, pas encore valide ou expiré | 422 | code |
| Bon personnel d'un autre client | 403 | code |
| Client sans compte actif, ou inactif / inconnu dans user-service (validate, apply) | 422 | userId |
| Niveau du client inférieur à `minTier` | 403 | code |
| Montant inférieur à `minAmount` | 422 | amount |
| Code épuisé (`usedCount = maxUses`) | 409 | code |
| Code déjà utilisé par ce client (`maxUsesPerUser` atteint) | 409 | code |
| Création : code déjà existant | 409 | code |
| Création : `validUntil ≤ validFrom`, PERCENT hors 1 à 50, valeur ≤ 0, format du code | 422 | champ concerné |
| Conversion : pas un multiple de 100, ou solde insuffisant | 422 | points |
| Suppression d'un code déjà utilisé | 409 | id |
| user-service ou reservation-service indisponible (arrêté, absent d'Eureka, 5xx, token de service impossible) | 503 | - |
| Réponse illisible d'un autre service | 502 | - |

Champ obligatoire absent → **400** ; valeur présente mais invalide → **422** (même règle que user-service).
`/api/promo-codes/check` fait les mêmes contrôles sans appeler user-service (aperçu rapide pour Angular).

**Concurrence** : `/apply` verrouille la ligne du code (`SELECT … FOR UPDATE`) : deux réservations simultanées ne dépassent pas `maxUses` ; la conversion verrouille le compte : deux conversions ne dépensent pas deux fois le solde.

## RabbitMQ (consommateur)

Règles de l'équipe : file **déclarée par le consommateur** (durable), **ack manuel**, **prefetch 1**, une seule remise en file puis `<file>.dlq`, **idempotence par `eventId`** (table `processed_events`, dans la même transaction que les effets).

| File | Producteur | Message → effet |
| --- | --- | --- |
| `loyalty.user_events` | user-service | **UserCreated** (rôle CLIENT seulement) : ouvre le compte, +100 points. **UserDeleted** : compte CLOSED, bons désactivés |
| `loyalty.reservation_completed` | reservation-service | **ReservationCompleted** : l'événement ne contient pas `userId` → OpenFeign `GET /internal/reservations/{id}`, puis EARN et recalcul du niveau. Même réservation reçue deux fois → comptée une fois |

| Situation | Décision |
| --- | --- |
| JSON illisible, `eventId` absent, type inattendu sur la file, champ absent, réservation inconnue (404) | nack sans remise → `.dlq` |
| `eventId` déjà traité | ack, rien n'est refait |
| erreur passagère (base, reservation-service ou Keycloak indisponible) | 1ʳᵉ fois : remis en file ; déjà remis : `.dlq` |
| client sans compte actif | ack, aucun point (journalisé) |

Messages (`docs/events/` : à documenter par user-service pour UserCreated / UserDeleted) :
```json
{ "eventId": "<uuid>", "type": "UserCreated", "occurredAt": "2026-11-20T09:00:00Z",
  "data": { "userId": "<uuid>", "email": "...", "firstname": "...", "lastname": "...", "role": "CLIENT" } }
{ "eventId": "<uuid>", "type": "UserDeleted", "occurredAt": "2026-11-20T09:00:00Z",
  "data": { "userId": "<uuid>", "deletedAt": "2026-11-20T09:00:00Z" } }
```
Tester sans les producteurs : console RabbitMQ → la file → **Publish message** (content_type `application/json`, delivery mode 2). Exemple : `docs/events/ReservationCompleted.json` sur `loyalty.reservation_completed` → client2 gagne 64 points (640 TND en BRONZE).

## Données de démonstration (`V2__demo_data.sql`)

| Client | UUID | Solde | Cumul | Niveau |
| --- | --- | --- | --- | --- |
| client1 | f4d1bab3-468d-428d-b02c-b44341430bfd | 1 240 | 1 640 | GOLD (+ bon `LOY-DEMO40` de 40 TND) |
| client2 | 611f32de-ce7b-4927-b1c4-d113b6479d59 | 180 | 180 | BRONZE |

| Code | UUID | Réduction | Scénario de test |
| --- | --- | --- | --- |
| BIENVENUE10 | 0a76460c-0f85-475e-9744-e5d95b3f32b9 | 10 % | valable jusqu'au 2027-12-31 → succès |
| ETE2026 | ef44685d-8991-42c6-92bc-8a4e576244ae | 15 % | expiré le 2026-09-30 → 422 |
| GOLD50 | bb097bb8-276b-4cb9-be20-ecb62740356e | 50.000 TND | réservé aux GOLD → client1 OK, client2 403 |
| FLASH100 | 4332ff93-5d22-4f6e-be31-13c015b1cfa4 | 20 % | maxUses = usedCount = 100 → 409 |
| WEEKEND | 98b85c18-198b-411c-9025-c480491dbe91 | 30.000 TND | minAmount 200.000 → 422 en dessous |

### Scénario de démonstration (5 minutes)
1. Publier un `UserCreated` (rôle CLIENT) sur `loyalty.user_events` : le compte apparaît avec **100 points** (Angular : Fidélité → Comptes clients).
2. client1 (GOLD) ouvre **Fidélité → Mes points** : carte GOLD, jauge, historique ; convertir **200 points** → bon `LOY-…` de 20 TND.
3. « Essayer un code promo » : **BIENVENUE10** accepté (prix barré) ; **ETE2026** → 422 ; client2 avec **GOLD50** → 403.
4. Publier `docs/events/ReservationCompleted.json` sur `loyalty.reservation_completed` : les points de client2 augmentent (+64).
5. Eureka montre **RENTAL-LOYALTY** UP à côté des 7 autres services.

## Tests

```powershell
.\mvnw.cmd test     # 78 tests, H2 en mode MySQL (mêmes migrations Flyway), sans Docker
```

| Fichier | Tests | Contenu |
| --- | --- | --- |
| `CalculationRulesTest` | 17 | points selon le niveau et arrondi, seuils BRONZE/SILVER/GOLD, la dépense ne fait pas descendre, réduction plafonnée et arrondie |
| `LoyaltyApiTest` | 20 | /me, historique paginé, conversion (201, 422, 400), bons, comptes, ajustement, 401 / 403, route /internal |
| `PromoCodeApiTest` | 30 | chaque ligne du tableau des codes HTTP (404, 422, 409, 403), CRUD, validate / apply idempotent, user-service 503 / 502 (faux client Feign) |
| `EventProcessorTest` | 11 | UserCreated / UserDeleted, ReservationCompleted (JSON officiel de `docs/events`), même `eventId` deux fois, même réservation deux fois, JSON illisible → DLQ, 503 → remise en file puis DLQ |

Horloge figée au 2026-11-20 dans les tests (bean `Clock`), tokens simulés (`spring-security-test`), clients OpenFeign simulés (`@MockBean`).

## Structure

```
src/main/java/com/location/loyaltyservice/
  account/    compte, transactions, niveaux (Tier), LoyaltyService, routes /api/loyalty et /internal/loyalty
  promo/      codes promo, utilisations, calcul de la réduction (Discount), PromoCodeService, routes /api/promo-codes et /internal/promo-codes
  messaging/  files RabbitMQ (RabbitConfig), EventProcessor (décision ack / remise en file / DLQ), EventListeners
  remote/     clients OpenFeign RENTAL-USER et RENTAL-RESERVATION, token de service, traduction 404 / 503 / 502
  security/   resource server Keycloak (claim roles), AccessGuard
  common/     format d'erreur, pagination, X-Correlation-Id, OpenAPI, santé, horloge
src/main/resources/db/migration/  V1__schema.sql, V2__demo_data.sql
```

## Pages Angular (`frontend/src/app/features/loyalty/`)

| Page | Route | Rôle | Contenu |
| --- | --- | --- | --- |
| Mes points | `/loyalty/me` | CLIENT | carte du niveau (couleur BRONZE / SILVER / GOLD), solde, jauge vers le niveau suivant, historique paginé, conversion, mes bons, essai d'un code |
| Codes promo | `/loyalty/admin/promo-codes` | ADMIN (AGENT en lecture) | tableau filtrable par état, `usedCount / maxUses`, modifier, désactiver, supprimer |
| Formulaire | `/loyalty/admin/promo-codes/new`, `…/:id/edit` | ADMIN | formulaire réactif, validation locale, erreurs 409 / 422 du serveur sous chaque champ |
| Comptes clients | `/loyalty/admin/accounts` | AGENT, ADMIN | liste filtrée par niveau, détail, ajustement de points (ADMIN) |
| `promo-code-input` | composant | CLIENT | champ réutilisable pour la page de réservation : `<app-promo-code-input [amount]="total" (applied)="code = $event?.code ?? null" />` → prix barré puis nouveau prix |

## Ce que les autres services doivent faire

| Membre | Tâche |
| --- | --- |
| Seddik | valider le port 8108 et RENTAL-LOYALTY dans `docs/conventions.md` (+ les 2 files dans le tableau RabbitMQ) |
| Montassar (user-service) | publier **UserCreated** après la création d'un utilisateur et **UserDeleted** après sa suppression dans `loyalty.user_events` (best effort : un log si RabbitMQ est absent) ; documenter les messages dans `docs/events/` |
| Aziz (reservation-service) | publier aussi **ReservationCompleted** dans `loyalty.reservation_completed` ; appeler `/internal/promo-codes/validate` puis `/apply` quand le client saisit un code ; utiliser le composant `promo-code-input` |

## Utilisation de l'IA

Ce service a été développé avec l'aide d'un assistant de programmation IA, à partir du cahier des charges « loyalty-service : fidélité et codes promo ».

- **Ce que l'IA a fait** : lecture des documents communs (`docs/`) et du code de user-service pris comme modèle ; génération du projet (pom, configuration), des migrations Flyway, des entités, des règles métier, des contrôleurs et de la documentation Swagger, du consommateur RabbitMQ, des clients OpenFeign, de la sécurité Keycloak, des 78 tests, des pages Angular, du Dockerfile, du bloc docker-compose, de la route Gateway, du client Keycloak et de ce README. Elle a aussi lancé les vérifications de bout en bout (MySQL réel, tokens Keycloak réels, Eureka, passage par la Gateway, messages publiés dans RabbitMQ, appels OpenFeign réels vers user-service et reservation-service).
- **Choix faits avec l'IA** là où le cahier des charges laissait la décision : un ajustement positif compte dans le cumul (un geste commercial peut faire monter de niveau) ; un client sans compte actif ne gagne pas de points (message acquitté et journalisé) ; `/check` n'appelle pas user-service (aperçu rapide) alors que `/validate` et `/apply` le font ; un autre code sur une réservation déjà remisée → 409 ; l'état calculé `state` (VALID, UPCOMING, EXPIRED, EXHAUSTED, INACTIVE) pour la page Angular.
- **Ce que l'auteur a fourni et vérifié** : *à compléter* (relecture du code, exécution des tests et du scénario de démonstration).
