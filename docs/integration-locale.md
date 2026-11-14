# Intégration locale : les 7 services sur un seul PC Windows

Guide testé le 10 novembre 2026 (scénario de bout en bout 10/10). PowerShell 5.1, à la racine du dépôt.
Scripts : `scripts/start-all.ps1`, `scripts/stop-all.ps1`, `scripts/reset-demo.ps1` (outils communs : `scripts/integration-tools.ps1`).

## 1. Prérequis

| Outil | Version | Remarque |
|---|---|---|
| Docker Desktop | lancé (WSL2) | infra : MySQL, Postgres, RabbitMQ, Keycloak, Eureka, Gateway, Config Server |
| PHP | **8.4** (pas 8.2 ni 8.3 : les `composer.lock` exigent 8.4) | `winget install --id PHP.PHP.8.4 --exact` ; extensions ci-dessous |
| Composer | 2.x | reservation, complaint |
| JDK | **21** (17 à 23 ; **pas 25** pour Spring Boot 3.3) | user-service |
| .NET SDK | **8** | contract-service (`net8.0`) |
| Node.js | 22 ou 24 | vehicle-service, frontend |
| Python | **3.12** | payment-service, ml-service |
| Symfony CLI | facultatif | sinon `composer serve:php` pour complaint |

**PHP 8.4** : copier `php.ini-development` en `php.ini` dans le dossier de PHP, puis activer (enlever le `;`) :
`extension_dir` en **chemin absolu** (`"C:\...\ext"` : un chemin relatif est lu depuis le dossier courant),
`extension=curl, fileinfo, intl, mbstring, openssl, pdo_mysql, sockets, sodium, zip` et `pdo_sqlite` (tests de reservation).

Les scripts trouvent PHP 8.4 et le JDK par paramètre (`-PhpHome`, `-JavaHome`), sinon par variable d'environnement,
sinon dans le PATH ; ils s'arrêtent avec un message clair si la version ne convient pas :
```powershell
$env:PHP84_HOME = "C:\chemin\vers\php-8.4"        # dossier qui contient php.exe
$env:JDK21_HOME = "C:\Program Files\Java\jdk-21"   # dossier du JDK
```

## 2. Réglages Windows (une seule fois)

- **Fichier hosts** : les services s'annoncent dans Eureka sous `host.docker.internal`. Windows doit savoir résoudre ce nom,
  sinon tous les appels `/internal` échouent (« Could not resolve host »). PowerShell **administrateur** :
  ```powershell
  Add-Content C:\Windows\System32\drivers\etc\hosts "`r`n127.0.0.1 host.docker.internal" -Encoding ASCII
  ipconfig /flushdns ; Resolve-DnsName host.docker.internal   # doit répondre 127.0.0.1
  ```
- **Port 3306** : un MySQL local (XAMPP : service Windows `mysql`) empêche le conteneur MySQL de démarrer. Administrateur :
  `Stop-Service mysql ; Set-Service mysql -StartupType Manual` (pour le remettre : `Set-Service mysql -StartupType Automatic ; Start-Service mysql`).
- Pare-feu : si Windows le demande pour java, php, node, python ou dotnet, autoriser **réseaux privés** seulement.

## 3. Installer chaque service et créer son `.env` (mode réel)

Infra d'abord : `cd infra ; copy .env.example .env ; docker compose up -d` (8 conteneurs avec config-server).
Pour chaque service : copier `.env.example` en `.env` (complaint : `.env.local`), puis mettre le **secret Keycloak du client
du même nom** (champ `secret` du client dans `infra/keycloak/location-voitures-realm.json`) et les **modes réels** :

| Service | Fichier | Réglages réels | Installation (dans `services/<service>`) |
|---|---|---|---|
| user (8101) | `.env` | `RESERVATIONS_MOCK=false` | rien (Flyway au démarrage) |
| vehicle (8102) | `.env` | `RESERVATION_MOCK=false` | `npm ci ; npx prisma generate ; npx prisma migrate deploy ; npm run seed` |
| reservation (8103) | `.env` | `LOOKUP_MODE=eureka` | `composer install ; php artisan key:generate ; php artisan migrate --seed` |
| payment (8104) | `.env` | `RESERVATION_CLIENT=http`, `EVENT_PUBLISHER=rabbitmq`, `AUTH_MODE=jwt`, `DJANGO_SECRET_KEY` (valeur aléatoire) | `py -3.12 -m venv venv ; .\venv\Scripts\python.exe -m pip install -r requirements.txt ; ... manage.py migrate ; ... manage.py seed_demo` |
| contract (8105) | `.env` | `USE_FAKE_SERVICES=false` | `dotnet restore` (migrations au démarrage) |
| complaint (8106) | `.env.local` | `LOOKUP_MODE=eureka` + `KEYCLOAK_CLIENT_SECRET` | `composer install ; php bin/console doctrine:migrations:migrate -n ; php bin/console doctrine:fixtures:load -n` |
| ml (8107) | `.env` | `EUREKA_ENABLED=true`, `RABBITMQ_ENABLED=true` (facultatif : `CONFIG_SERVER_URL=http://localhost:8888`) | `py -3.12 -m venv .venv ; .\.venv\Scripts\python.exe -m pip install -r requirements.txt ; .\.venv\Scripts\alembic.exe upgrade head` |
| frontend (4200) | — | — | `npm ci` (dans `frontend`) |

Les `.env` ne sont **jamais** commités (vérifier avec `git check-ignore -v <fichier>`).

## 4. Lancer, vérifier, arrêter

```powershell
.\scripts\start-all.ps1      # vérifie la machine, infra, puis une fenêtre par processus dans l'ordre :
                             # user, vehicle (+consumer), ml -> reservation (serve, eureka, consume) -> payment (+consume_events)
                             # -> contract -> complaint (serve, eureka) -> frontend ; échauffement ; contrôle
.\scripts\stop-all.ps1       # ferme les fenêtres et nettoie Eureka ; -Infra arrête aussi les conteneurs
```
**Vérification attendue** (affichée à la fin de `start-all`) :
- Eureka (http://localhost:8761) : **7 services + API-GATEWAY UP** (+ CONFIG-SERVER) ;
- RabbitMQ (http://localhost:15672, guest/guest) : **6 files** (`reservation.payment_succeeded`, `payment.reservation_cancelled`,
  `vehicle.contract_events`, `payment.return_settled`, `reservation.contract_closed`, `ml.reservation_completed`),
  chacune avec sa `.dlq` et **1 consommateur**.

**Remise à zéro des données de démo** (services arrêtés, infra lancée) : `.\scripts\stop-all.ps1 ; .\scripts\reset-demo.ps1 ; .\scripts\start-all.ps1`.
Le script remet vehicle, reservation, complaint, payment, recrée `contract_db`, nettoie `ml_db`, vide les files et contrôle les quantités.

## 5. Config Server (port 8888)

`infra/config-server` (Spring Cloud Config, profil native) sert `infra/config-repo` et s'inscrit sous CONFIG-SERVER.
Exemple : http://localhost:8888/rental-ml/default. Aucun secret dans `config-repo` (ils restent dans les `.env`).
**Les 8 services** lisent au démarrage `SERVICE_PORT`, `SERVICE_HOST`, `EUREKA_URL` et `KEYCLOAK_ISSUER` dans
`rental-<service>.yml` + `application.yml` (`CONFIG_SERVER_URL`, `http://localhost:8888` par défaut, `http://config-server:8888` dans Docker).
Priorité : variable d'environnement ou `.env` > Config Server > valeur par défaut du code ; ces 4 clés sont donc commentées dans les
`.env.example` (les décommenter pour forcer une valeur locale). Config Server arrêté : avertissement et démarrage avec les valeurs par défaut.

| Service | Client du Config Server |
|---|---|
| user, loyalty (Spring Boot) | `spring-cloud-starter-config` : `spring.config.import: optional:configserver:` |
| payment (Django) | `payments/config_server.py`, appelé dans `config/settings.py` |
| reservation (Laravel) | `app/Support/ConfigServer.php`, après le chargement du `.env` (`bootstrap/app.php`), cache 60 s |
| complaint (Symfony) | `src/Config/ConfigServerEnvVarLoader.php` (`EnvVarLoaderInterface`), cache 60 s |
| vehicle (Next.js) | `lib/config-server.ts`, appelé dans `instrumentation.ts` et `consumer/index.ts` |
| contract (ASP.NET Core) | `ConfigServerEnv.cs`, appelé au début de `Program.cs` |
| ml (FastAPI) | `app/config_server.py` (premier service branché) |

Démo : changer une valeur dans `infra/config-repo/rental-<service>.yml`, relancer le service, la voir dans son journal (`[config] ...`).

## 6. Inscription (sign up) Keycloak

PR `feature/keycloak-registration` : `registrationAllowed=true` et groupe par défaut `clients` (rôle CLIENT).
Un nouvel inscrit reçoit **CLIENT seulement** ; les comptes existants ne changent pas (un groupe par défaut ne s'applique
qu'aux nouveaux comptes, contrairement aux rôles par défaut). **Limite** : user-service ne crée pas de profil automatiquement :
`GET /api/users/me` répond 404 et la réservation échoue (« Client introuvable ») tant que le profil (`POST /api/users`, soi-même)
et un permis vérifié par un agent n'existent pas. Sur un realm déjà importé, appliquer les réglages avec `kcadm.sh` ou la console.

## 7. Problèmes connus

| Symptôme | Cause / solution |
|---|---|
| vehicle : « @prisma/client did not initialize yet » | `npx prisma generate` après `npm ci` |
| vehicle : 1re requête lente, `AGENTS.md` non suivi | Next.js compile au 1er appel ; `next dev` crée `AGENTS.md` (ne pas le commiter) |
| complaint : 1re requête ~10 s (13 s via la Gateway) | cache Symfony ; `start-all` fait l'échauffement |
| complaint : `config/reference.php` modifié | régénéré par `composer install` (fins de ligne) : `git restore` |
| tests reservation : erreur SQLite | activer `extension=pdo_sqlite` dans le php.ini de PHP 8.4 |
| 503 « Service RENTAL-... injoignable » | fichier hosts (section 2), ou service absent d'Eureka (cache Gateway ~30 s) |
| Eureka « EMERGENCY » en rouge | auto-préservation, normal en développement |

## 8. Scénario de démo (dans l'interface, http://localhost:4200)

| # | Qui | Action | Attendu |
|---|---|---|---|
| 1 | visiteur | liste des véhicules | véhicules affichés |
| 2 | client1 | réserve un véhicule libre | PENDING, prix fixe et caution, prix conseillé par ml-service |
| 3 | client2 | essaie de réserver | refus 422 (permis expiré) |
| 4 | client1 | paie la location (RENTAL) puis la caution (DEPOSIT) | réservation CONFIRMED |
| 5 | agent1 | crée le contrat, remet le véhicule | contrat ACTIVE, véhicule RENTED |
| 6 | agent1 | enregistre le retour (km, carburant) | véhicule AVAILABLE, frais PENDING, caution remboursée, réservation COMPLETED |
| 7 | client1 | ouvre une réclamation sur cette réservation | 201 ; 403 pour la réservation d'un autre |
| 8 | agent1 / admin1 | traite puis supprime la réclamation | statut mis à jour, suppression ADMIN |
| 9 | admin1 | page ML | prédiction de prix, nouvelle ligne d'entraînement après l'étape 6 |
| 10 | client1 | annule une autre réservation payée | CANCELLED, paiements remboursés |

Comptes : `client1`, `client2` (permis expiré), `agent1`, `admin1`, mot de passe `<nom>_pwd`.
En cas d'échec : réponse (`{status, error, message}`), `X-Correlation-Id` dans les journaux des fenêtres, console RabbitMQ (`.dlq`), Eureka.
