# complaint-service

Service des **réclamations** de l'application de location de voitures (module de Seddik).
Un client dépose une réclamation sur une réservation ou un contrat ; les agents et administrateurs la traitent
(statut, priorité, réponses) jusqu'à sa résolution ou son rejet.

| | |
| --- | --- |
| Pile | Symfony 7.4, PHP 8.5, Doctrine ORM 3, MySQL 8.4 (`complaint_db`) |
| Port | 8106 |
| Nom Eureka | `RENTAL-COMPLAINT` |
| Routes publiques | `/api/complaints/**`, via la Gateway (http://localhost:8088 → `lb://RENTAL-COMPLAINT`) |
| Sécurité | access token Keycloak (realm `location-voitures`), vérifié par le service |
| Documentation | Swagger UI http://localhost:8106/api/doc ; contrat partagé `docs/openapi/complaint-service.yaml` |
| Interface | pages Angular dans `frontend/src/app/features/complaints/` |

Conventions communes de l'équipe : `docs/conventions.md` (JSON camelCase, pagination `{items, page, size, total}`,
erreurs `{status, error, message, details}`), `docs/internal-api.md`, `docs/demo-ids.md`.

## Sommaire

- [Endpoints](#endpoints)
- [Règles métier et codes de réponse](#règles-métier-et-codes-de-réponse)
- [Lancer en local](#lancer-en-local)
- [Lancer en Docker](#lancer-en-docker)
- [Variables d'environnement](#variables-denvironnement)
- [Tests](#tests)
- [Sécurité](#sécurité)
- [Appels aux autres services](#appels-aux-autres-services)
- [Données de démonstration](#données-de-démonstration)
- [Utilisation de l'IA](#utilisation-de-lia)

## Endpoints

Toutes les routes `/api/**` demandent un token avec le rôle CLIENT, AGENT ou ADMIN.

| Méthode et route | Qui | Rôle | Succès |
| --- | --- | --- | --- |
| `GET /api/complaints` | tous | Liste paginée, plus récentes d'abord ; filtres `page`, `size` (1 à 100), `status`, `category`, `priority`, `userId`. Un CLIENT ne voit que les siennes. | 200 |
| `POST /api/complaints` | CLIENT | Crée une réclamation sur une réservation **ou** un contrat. | 201 + `Location` |
| `GET /api/complaints/{id}` | auteur, AGENT, ADMIN | Détail avec `messages`, `statusHistory` et `allowedTransitions`. | 200 |
| `PUT /api/complaints/{id}` | auteur | Remplace `subject`, `description`, `category`. | 200 |
| `DELETE /api/complaints/{id}` | auteur, ADMIN | Supprime la réclamation, ses messages et son historique. | 204 |
| `PATCH /api/complaints/{id}/status` | AGENT, ADMIN | Change le statut, avec un commentaire facultatif gardé dans l'historique. | 200 |
| `PATCH /api/complaints/{id}/priority` | AGENT, ADMIN | Change la priorité (non gardée dans l'historique). | 200 |
| `POST /api/complaints/{id}/messages` | auteur, AGENT, ADMIN | Ajoute un message ; le rôle de l'auteur est enregistré. | 201 |
| `GET /health` | public | `{"status":"UP"}` | 200 |
| `GET /api/doc`, `/api/doc.json` | public | Swagger UI et contrat OpenAPI. | 200 |

Corps de création :

```json
{
  "reservationId": "33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8",
  "contractId": null,
  "category": "VEHICLE",
  "priority": "HIGH",
  "subject": "Climatisation en panne",
  "description": "La climatisation ne souffle que de l'air chaud."
}
```

Valeurs : `category` BILLING, VEHICLE, SERVICE, DAMAGE_DISPUTE ; `priority` LOW, MEDIUM (par défaut), HIGH ;
`status` OPEN, IN_PROGRESS, RESOLVED, REJECTED. Sujet : 150 caractères au plus, description : 5000, message : 2000, commentaire : 1000.

## Règles métier et codes de réponse

**Cycle de vie** (règle d'équipe, dans `ComplaintStatus::allowedTransitions()`) :

```
OPEN ──► IN_PROGRESS ──► RESOLVED  (final)
  │          │  ▲
  │          │  └── retour à OPEN possible
  └──────────┴────► REJECTED  (final)
```

- Une réclamation commence en OPEN ; la création est la première entrée de l'historique.
- RESOLVED et REJECTED sont **finaux** : plus de transition, de message ni de changement de priorité (409).
- Seul l'auteur modifie, et seulement en OPEN (409 sinon). L'auteur supprime en OPEN ; un ADMIN supprime quel que soit le statut.
- Une réclamation porte **exactement une** référence : `reservationId` ou `contractId` (aucune ou les deux → 422).
  La réservation ou le contrat doit exister (404) et appartenir au client (403).

| Code | Quand |
| --- | --- |
| 400 | Corps JSON invalide ou mal formé, champ manquant ou trop long, filtre invalide (`details` liste les champs) |
| 401 | Token absent, invalide, mal signé, d'un autre émetteur ou expiré |
| 403 | Rôle insuffisant, réclamation, réservation ou contrat d'un autre client, CLIENT qui filtre sur un autre `userId` |
| 404 | Réclamation, réservation ou contrat introuvable ; route inconnue |
| 409 | Transition interdite (avec les transitions possibles dans `details`), réclamation finale, modification hors OPEN |
| 415 | Corps envoyé sans `Content-Type: application/json` |
| 422 | Aucune ou deux références (réservation et contrat) |
| 502 | Réponse illisible de reservation-service ou contract-service |
| 503 | reservation-service, contract-service, Eureka ou Keycloak indisponible |

**Ordre des contrôles.** Le corps JSON est validé avant d'entrer dans le contrôleur : un corps invalide renvoie donc
**400 même si la réclamation n'existe pas**. Viennent ensuite 404, 403, puis 409 / 422.

## Lancer en local

L'infrastructure doit tourner : `docker compose up -d` dans `infra/` (MySQL, Keycloak, Eureka, Gateway, RabbitMQ).
Première installation, dans `services/complaint-service` :

```
composer install
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:fixtures:load --no-interaction    # données de démonstration
```

Créez `.env.local` (non versionné) avec au moins le secret du client Keycloak (champ `secret` du client `complaint-service`
dans `infra/keycloak/location-voitures-realm.json`) :

```
KEYCLOAK_CLIENT_SECRET=<secret>
```

Puis, dans deux terminaux :

```
composer serve      # serveur web sur le port 8106, toutes les interfaces
composer eureka     # enregistrement RENTAL-COMPLAINT dans Eureka, battement toutes les 30 s
```

`composer serve` lance `symfony server:start --port=8106 --no-tls --allow-all-ip`. Sans Symfony CLI : `composer serve:php`
(`php -S 0.0.0.0:8106 -t public`).

**Écoute réseau.** La Gateway tourne dans Docker et joint le service via `host.docker.internal`, qui n'arrive pas sur `127.0.0.1` :
le serveur doit écouter sur toutes les interfaces, ce que font les deux commandes. Si le pare-feu Windows demande une autorisation
au premier lancement, **acceptez pour les réseaux privés**.

**Eureka.** Le service s'annonce sous `host.docker.internal:8106` (visible sur http://localhost:8761). Arrêtez `composer eureka`
avec **Ctrl+C** : la commande se retire d'Eureka avant de quitter. Si le terminal a été fermé sans Ctrl+C, retirez l'instance avec
`php bin/console app:eureka:deregister`, sinon l'auto-préservation d'Eureka peut la garder affichée.
Après l'arrêt du service, la Gateway peut répondre **500 pendant une quinzaine de secondes** avant **503** : elle garde la liste
des instances en cache jusqu'au rafraîchissement depuis Eureka.

`app:eureka:run` n'utilise pas `EurekaRunner` de `shared/php-eureka`, qui boucle sans fin sans moyen de s'arrêter : la commande
refait la même boucle et ajoute le retrait sur Ctrl+C / SIGTERM. **À faire avec Aziz** (reservation-service utilise aussi le runner
partagé) : ajouter un arrêt propre à `EurekaRunner`, puis faire revenir cette commande au runner partagé.

## Lancer en Docker

Les conteneurs du service sont dans `infra/docker-compose.yml` sous le **profil `services`** : `docker compose up -d` continue à ne
lancer que l'infrastructure. Depuis `infra/` :

```
cp .env.example .env    # une seule fois ; contient COMPLAINT_KEYCLOAK_CLIENT_SECRET (secret de dev du realm)
docker compose --profile services up -d --build complaint-service complaint-eureka
docker compose --profile services stop complaint-service complaint-eureka    # avant de revenir à composer serve (port 8106)
```

| Conteneur | Rôle |
| --- | --- |
| `complaint-service` | migrations Doctrine au démarrage (en attendant MySQL), puis serveur web sur `0.0.0.0:8106` ; healthcheck sur `/health` |
| `complaint-eureka` | même image, `php bin/console app:eureka:run` ; démarre quand `complaint-service` est healthy ; `docker stop` le retire d'Eureka |

Image (`Dockerfile`) : `php:8.5-cli-alpine` avec `pdo_mysql` et `pcntl` (`curl` est déjà compilé dans l'image officielle),
`composer install --no-dev` avec autoloader optimisé, `APP_ENV=prod`, cache préchauffé, utilisateur `www-data`.
Le serveur est `php -S` avec 4 processus (`PHP_CLI_SERVER_WORKERS`) : suffisant pour le projet, pas pour une vraie production.

Le client Eureka partagé (`../../shared/php-eureka`) est hors du dossier du service : compose le passe comme contexte de build
supplémentaire (`additional_contexts: shared: ../shared`). Pour construire l'image à la main, depuis `services/complaint-service` :
`docker build --build-context shared=../../shared -t complaint-service .`

Dans le conteneur, Keycloak est joint par `keycloak:8080` (`KEYCLOAK_JWKS`, `KEYCLOAK_TOKEN_URL`) alors que l'émetteur des tokens
reste `http://localhost:8180/realms/location-voitures`, comme pour la Gateway.

## Variables d'environnement

Valeurs par défaut dans `.env.dist` et `config/services.yaml` ; surcharges locales dans `.env.local` ; en Docker, dans `infra/docker-compose.yml`.

| Variable | Défaut (local) | Rôle |
| --- | --- | --- |
| `APP_ENV`, `APP_SECRET` | `dev`, vide | environnement Symfony ; en Docker `prod` et `COMPLAINT_APP_SECRET` de `infra/.env` |
| `DATABASE_URL` | `mysql://complaint_svc:complaint_pwd@127.0.0.1:3306/complaint_db?...` | base MySQL (Docker : `mysql:3306`) |
| `SERVICE_PORT` | `8106` | port annoncé à Eureka (et port d'écoute en Docker) |
| `SERVICE_HOST` | `host.docker.internal` | adresse annoncée à Eureka, jointe par la Gateway |
| `EUREKA_URL` | `http://localhost:8761/eureka` | serveur Eureka (Docker : `http://eureka:8761/eureka`) |
| `KEYCLOAK_ISSUER` | `http://localhost:8180/realms/location-voitures` | émetteur attendu dans les tokens |
| `KEYCLOAK_JWKS` | vide = `{KEYCLOAK_ISSUER}/protocol/openid-connect/certs` | adresse des clés de signature |
| `KEYCLOAK_TOKEN_URL` | vide = `{KEYCLOAK_ISSUER}/protocol/openid-connect/token` | adresse du token de service (client credentials) |
| `KEYCLOAK_CLIENT_ID` | `complaint-service` | client Keycloak du service |
| `KEYCLOAK_CLIENT_SECRET` | vide (à mettre dans `.env.local`) | son secret ; Docker : `COMPLAINT_KEYCLOAK_CLIENT_SECRET` de `infra/.env` |
| `LOOKUP_MODE` | `demo` | `demo` (réservations et contrats simulés) ou `eureka` (vrais appels) ; Docker : `COMPLAINT_LOOKUP_MODE` |
| `RABBITMQ_URL` | `amqp://guest:guest@localhost:5672` | prévu par les conventions ; le service ne publie ni ne consomme encore d'événement |

## Tests

```
composer test
```

Cette commande recrée la base `complaint_db_test`, applique les migrations et les fixtures, puis lance toute la suite PHPUnit
(**183 tests**). Chaque test s'exécute dans une transaction annulée à la fin (dama/doctrine-test-bundle) : les tests repartent
tous des fixtures et ne dépendent pas de leur ordre. PHPUnit échoue sur les dépréciations, notices et avertissements.

- Un seul test, une fois la base créée : `php bin/phpunit --filter ChangeStatusTest` ; recréer seulement la base : `composer test:db`.
- Tests fonctionnels (`tests/Functional`) : chaque route, règle métier et code d'erreur. L'appelant est donné par les en-têtes
  `X-Dev-User-Id` / `X-Dev-Roles`, acceptés **uniquement en test**.
- `JwtAuthenticationTest` : vrais JWT signés par une clé de test (`tests/Fixtures/jwt`, sans lien avec Keycloak) : token valide,
  expiré, sans expiration, mauvais émetteur, mauvaise signature, clé inconnue, HS256, sans rôle.
- Tests unitaires (`tests/Unit`) : entités, enums, DTO, appels internes (`MockHttpClient`), chargement des clés JWKS.
- Les appels internes utilisent toujours le mode `demo` en test (`.env.test`) : ni Eureka ni Keycloak ne sont nécessaires.

### Droits MySQL sur la base de test

Le compte `complaint_svc` doit avoir les droits sur `complaint_db_test`. `infra/mysql/init.sql` les donne pour les nouvelles
installations, mais ce script ne s'exécute qu'au **premier** démarrage de MySQL. Si votre volume MySQL existe déjà, lancez une fois,
depuis `infra/` :

PowerShell :
```
Get-Content ..\services\complaint-service\scripts\grant-test-db.sql | docker compose exec -T mysql sh -c 'mysql -uroot -p$MYSQL_ROOT_PASSWORD'
```

Git Bash :
```
docker compose exec -T mysql sh -c 'mysql -uroot -p$MYSQL_ROOT_PASSWORD' < ../services/complaint-service/scripts/grant-test-db.sql
```

Le script [scripts/grant-test-db.sql](scripts/grant-test-db.sql) donne les droits sur les bases `complaint_db%`
(`GRANT ALL PRIVILEGES ON \`complaint_db%\`.* TO 'complaint_svc'@'%'`).

## Sécurité

Chaque requête sur `/api/**` porte un access token Keycloak : `Authorization: Bearer <token>`. La Gateway le vérifie, et le service
le vérifie **aussi lui-même**, en dev comme en prod (`App\Security\JwtAuthenticator`) :

- signature RS256 avec les clés publiques du realm (`KEYCLOAK_JWKS`), gardées en cache une heure, rechargées si le token cite une
  clé inconnue (au plus une fois par minute) ; la clé de chiffrement publiée par Keycloak est ignorée ;
- émetteur = `KEYCLOAK_ISSUER`, expiration obligatoire (10 s de tolérance), access token uniquement ;
- l'appelant est le `sub` ; ses rôles sont ceux de la claim `roles` parmi CLIENT, AGENT, ADMIN (les rôles par défaut de Keycloak sont ignorés).

| Situation | Réponse |
| --- | --- |
| token absent | 401 `Authentification requise` |
| token mal formé, mal signé, d'un autre émetteur, expiré, en HS256 | 401 avec la raison, `WWW-Authenticate: Bearer error="invalid_token"` |
| token sans rôle CLIENT, AGENT ni ADMIN (ex. compte de service INTERNAL) | 403 `Accès refusé : rôle CLIENT, AGENT ou ADMIN requis` |
| clés de Keycloak impossibles à charger | 503 |

Les droits sur une réclamation (qui peut voir, modifier, supprimer, traiter) sont dans `App\Security\ComplaintVoter`.
`/health` et `/api/doc` restent publics, même avec un token invalide.

Obtenir un token pour essayer (Git Bash ; secret du client `gateway` dans `infra/keycloak/location-voitures-realm.json`) :

```
curl -s -X POST http://localhost:8180/realms/location-voitures/protocol/openid-connect/token \
  -d grant_type=password -d client_id=gateway -d client_secret=<secret> -d username=client1 -d password=client1_pwd
curl -s http://localhost:8088/api/complaints -H "Authorization: Bearer <access_token>"
```

## Appels aux autres services

À la création d'une réclamation, le service vérifie que la réservation ou le contrat existe et appartient au client :
`GET /internal/reservations/{id}` sur RENTAL-RESERVATION, ou `GET /internal/contracts/{id}` sur RENTAL-CONTRACT.

- L'adresse vient d'Eureka. L'appel porte un **jeton de service** obtenu par client credentials avec le client Keycloak
  `complaint-service` (rôle INTERNAL), gardé en cache jusqu'à 30 s avant son expiration ; après un 401, un nouveau jeton est
  demandé et l'appel réessayé une fois.
- L'en-tête `X-Correlation-Id` reçu est retransmis tel quel (généré s'il manque) et renvoyé dans chaque réponse du service.
- Erreurs : objet inconnu → 404 ; appartenant à un autre client → 403 ; service absent d'Eureka, sans instance UP, injoignable,
  ou Keycloak indisponible → **503** ; réponse illisible (JSON invalide, champ manquant, code inattendu, jeton refusé) → **502**.
  Le détail réseau est écrit dans le log du service, pas dans la réponse.

**`LOOKUP_MODE`** choisit l'implémentation :

- `demo` (**par défaut**, tant que reservation-service et contract-service n'ont pas leurs routes `/internal`) : réservations
  et contrats de `docs/demo-ids.md`, sans appel réseau ;
- `eureka` : vrais appels HTTP, comme décrit ci-dessus. À mettre dans `.env.local` (ou `COMPLAINT_LOOKUP_MODE` en Docker).

Formats attendus et traduction des erreurs : `docs/internal-api.md`, section complaint-service.

## Données de démonstration

`php bin/console doctrine:fixtures:load` (base vidée puis rechargée) crée 5 réclamations avec les UUID fixes de `docs/demo-ids.md` :

| Réclamation | Client | Concerne | Catégorie | Priorité | Statut |
| --- | --- | --- | --- | --- | --- |
| rec-1 « Montant facturé supérieur au devis » | client2 | r-completed | BILLING | MEDIUM | OPEN |
| rec-2 « Voyant moteur allumé pendant la location » | client2 | r-completed | VEHICLE | HIGH | RESOLVED |
| « Rayure contestée au retour » | client2 | contrat c-completed | DAMAGE_DISPUTE | HIGH | OPEN |
| « Agence injoignable par téléphone » | client1 | r-confirmed | SERVICE | LOW | IN_PROGRESS |
| « Caution trop élevée » | client1 | r-pending | BILLING | MEDIUM | REJECTED |

Comptes Keycloak : `client1`, `client2`, `agent1`, `admin1`, mot de passe `<nom>_pwd`. client1 voit 2 réclamations, client2 en voit 3,
agent1 et admin1 les 5.

## Utilisation de l'IA

Conformément aux règles de l'atelier, voici comment l'IA a été utilisée pour ce service.

**Outil.** Claude Code (Anthropic), utilisé dans VS Code sur toute la durée du module. Tous les commits de la branche
`feature/complaint-service` portent la mention `Co-Authored-By: Claude`.

**Ce que Claude Code a fait.** À partir de mes consignes, étape par étape, Claude Code a écrit la quasi-totalité du code et de la
documentation de ce dossier, ainsi que les pages Angular de `frontend/src/app/features/complaints/` :

- squelette Symfony, entités, migration, fixtures, CRUD, règles métier, format d'erreur commun, documentation OpenAPI ;
- tests PHPUnit (fonctionnels et unitaires), y compris les JWT de test ;
- enregistrement Eureka, appels internes (Eureka, jeton de service, traduction des erreurs, mode `demo`), authentification JWT Keycloak ;
- pages Angular (liste, création, détail, traitement par les agents) ;
- Dockerfile, services du profil `services` dans `infra/docker-compose.yml`, ce README et `CLAUDE.md`.

Claude Code a aussi lancé les commandes de vérification : suite PHPUnit, build Angular, appels réels via la Gateway avec des
tokens Keycloak, parcours des pages dans un navigateur piloté par script (client1, agent1, admin1), build et démarrage des
conteneurs Docker. Il a signalé les points à trancher et ce qu'il n'avait pas pu vérifier.

**Ce que j'ai fait et vérifié.**

- J'ai défini le périmètre et les consignes de chaque étape, et fixé les règles métier : matrice des statuts, états finaux,
  priorité réservée aux agents, une réservation **ou** un contrat, suppression par l'ADMIN.
- J'ai tranché les choix que Claude Code me soumettait : mode `demo` par défaut, 403 pour un token sans rôle,
  une seule page de liste pour tous les rôles, clés de test versionnées…
- J'ai relu les résultats présentés à chaque étape (sorties de tests, réponses HTTP, captures d'écran) avant de valider
  et de faire commiter.
- J'ai géré l'intégration avec l'équipe : correctif de la Gateway fusionné dans `main`, merge de `main` dans ma branche, pull requests.

**Limites.** La vérification du code produit par l'IA repose sur les tests automatiques et les essais décrits ci-dessus.
Les appels réels vers reservation-service et contract-service n'ont pas encore pu être testés, car leurs routes `/internal`
n'existent pas encore (mode `demo` en attendant).
