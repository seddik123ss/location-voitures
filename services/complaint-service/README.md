# complaint-service

Réclamations des clients : Symfony 7.4, PHP 8.5, MySQL `complaint_db`, port 8106, nom Eureka `RENTAL-COMPLAINT`.

> README en cours : il sera complété à l'étape README (lancement, configuration, Eureka, Docker).

## Lancer le service

L'infrastructure doit tourner (`docker compose up -d` dans `infra/`). Ouvrez deux terminaux dans `services/complaint-service` :

```
composer serve      # serveur web sur le port 8106, toutes les interfaces
composer eureka     # enregistrement RENTAL-COMPLAINT dans Eureka, battement toutes les 30 s
```

`composer serve` lance `symfony server:start --port=8106 --no-tls --allow-all-ip`. Sans Symfony CLI, utilisez
`composer serve:php` (`php -S 0.0.0.0:8106 -t public`).

**Écoute réseau.** La Gateway tourne dans Docker et joint le service via `host.docker.internal`, qui n'arrive pas sur `127.0.0.1` :
le serveur doit écouter sur toutes les interfaces, ce que font les deux commandes. Si le pare-feu Windows demande une autorisation
au premier lancement, **acceptez pour les réseaux privés**.

**Eureka.** Le service s'annonce sous `host.docker.internal:8106` (`SERVICE_HOST`, `SERVICE_PORT`, `EUREKA_URL`), visible sur
http://localhost:8761. Arrêtez `composer eureka` avec **Ctrl+C** : la commande se retire d'Eureka avant de quitter. Si le terminal
a été fermé sans Ctrl+C, retirez l'instance avec `php bin/console app:eureka:deregister` ; sinon l'auto-préservation d'Eureka peut
la garder affichée.

Après l'arrêt du service, la Gateway peut répondre **500 pendant une quinzaine de secondes** avant de répondre **503** : c'est
normal. Elle garde la liste des instances en cache et tente encore l'ancienne adresse, jusqu'au rafraîchissement depuis Eureka.

`app:eureka:run` n'utilise pas `EurekaRunner` de `shared/php-eureka`, qui boucle sans fin sans moyen de s'arrêter : la commande
refait la même boucle (enregistrement, battement toutes les 30 s, nouvel enregistrement si Eureka a oublié l'instance) et ajoute
le retrait sur Ctrl+C / SIGTERM. **À faire plus tard, avec Aziz** (reservation-service utilise aussi le runner partagé) : ajouter
un arrêt propre à `EurekaRunner`, puis faire revenir cette commande au runner partagé.

Vérification via la Gateway (http://localhost:8088) : `GET /api/complaints` sans token donne 401 (Gateway). Avec un token Keycloak,
on obtient 200 (voir « Sécurité »).

## Sécurité

Chaque requête sur `/api/**` porte un access token Keycloak : `Authorization: Bearer <token>` (realm `location-voitures`).
Le service le vérifie lui-même, en dev comme en prod :

- signature RS256 avec les clés publiques du realm (`{KEYCLOAK_ISSUER}/protocol/openid-connect/certs`, gardées en cache une heure,
  rechargées si le token cite une clé inconnue) ;
- émetteur = `KEYCLOAK_ISSUER`, expiration obligatoire (10 s de tolérance) ;
- l'appelant est le `sub` ; ses rôles sont ceux de la claim `roles` parmi CLIENT, AGENT, ADMIN (les rôles par défaut de Keycloak sont ignorés).

Réponses : token absent, invalide, mal signé, d'un autre émetteur ou expiré → **401** au format d'erreur commun ; token sans rôle
CLIENT, AGENT ou ADMIN (par exemple un compte de service INTERNAL) → **403** ; Keycloak injoignable au premier chargement des clés → **503**.
`/health` et `/api/doc` restent publics, même avec un token invalide.

Obtenir un token pour essayer (Git Bash ; secret du client `gateway` dans `infra/keycloak/location-voitures-realm.json`) :

```
curl -s -X POST http://localhost:8180/realms/location-voitures/protocol/openid-connect/token \
  -d grant_type=password -d client_id=gateway -d client_secret=<secret> -d username=client1 -d password=client1_pwd
curl -s http://localhost:8088/api/complaints -H "Authorization: Bearer <access_token>"
```

Les en-têtes `X-Dev-User-Id` / `X-Dev-Roles` ne fonctionnent plus qu'en test (`when@test`).

## Appels aux autres services

À la création d'une réclamation, le service vérifie que la réservation ou le contrat existe et appartient au client :
`GET /internal/reservations/{id}` sur RENTAL-RESERVATION, ou `GET /internal/contracts/{id}` sur RENTAL-CONTRACT (docs/internal-api.md).

- L'adresse vient d'Eureka. L'appel porte un **jeton de service** obtenu par client credentials avec le client Keycloak
  `complaint-service` (rôle INTERNAL). Ce jeton reste en cache jusqu'à 30 s avant son expiration ; après un 401, un nouveau jeton est demandé et l'appel est réessayé une fois.
- L'en-tête `X-Correlation-Id` reçu est retransmis tel quel. S'il est absent, il est généré. Il est aussi renvoyé dans chaque réponse du service.
- Erreurs : objet inconnu du service appelé → 404 ; appartenant à un autre client → 403 ; service inconnu d'Eureka, sans instance UP,
  injoignable, ou Keycloak indisponible → **503** ; réponse illisible (JSON invalide, champ manquant, code inattendu, jeton refusé) → **502**.
  Le détail réseau (adresse, message du système) est écrit dans le log du service, pas dans la réponse.

Configuration (`.env.local`) :

```
KEYCLOAK_CLIENT_ID=complaint-service
KEYCLOAK_CLIENT_SECRET=<champ "secret" du client complaint-service dans infra/keycloak/location-voitures-realm.json>
LOOKUP_MODE=demo   # ou eureka
```

`LOOKUP_MODE` choisit l'implémentation : `demo` (par défaut : réservations et contrats de `docs/demo-ids.md`, sans appel)
ou `eureka` (vrais appels HTTP). Le défaut reste `demo` tant que reservation-service et contract-service n'ont pas leurs
routes `/internal` ; pour essayer les vrais appels, mettre `LOOKUP_MODE=eureka` dans `.env.local`.
Formats attendus et traduction des erreurs : `docs/internal-api.md`, section complaint-service.
Les tests utilisent toujours `demo` (`.env.test`).

## Documentation de l'API

- Swagger UI : http://localhost:8106/api/doc, JSON : http://localhost:8106/api/doc.json (sans authentification).
- Contrat partagé : `docs/openapi/complaint-service.yaml`, régénéré avec `composer openapi:dump`.

**Ordre des contrôles.** Le corps JSON est validé avant d'entrer dans le contrôleur. Un corps invalide renvoie donc
**400 même si la réclamation n'existe pas**. Viennent ensuite 404 (réclamation introuvable), 403 (droits), puis 409 / 422 (règles métier).

## Tests

```
composer test
```

Cette commande enchaîne :
1. la suppression puis la création de la base `complaint_db_test` ;
2. les migrations ;
3. les fixtures ;
4. toute la suite PHPUnit.

Chaque test s'exécute dans une transaction annulée à la fin (dama/doctrine-test-bundle) : les tests repartent tous des fixtures et ne dépendent pas de leur ordre.

Une fois la base de test créée, on peut lancer un seul test : `php bin/phpunit --filter ChangeStatusTest`.
Pour recréer seulement la base de test : `composer test:db`.

En test, l'appelant est donné par les en-têtes de développement `X-Dev-User-Id` et `X-Dev-Roles`. `JwtAuthenticationTest` utilise
de vrais JWT signés par une clé de test (`tests/Fixtures/jwt`, sans lien avec Keycloak).

### Droits MySQL sur la base de test

Le compte `complaint_svc` doit avoir les droits sur `complaint_db_test`. `infra/mysql/init.sql` les donne pour les nouvelles installations,
mais ce script ne s'exécute qu'au **premier** démarrage de MySQL. Si votre volume MySQL existe déjà, lancez une fois, depuis `infra/` :

PowerShell :
```
Get-Content ..\services\complaint-service\scripts\grant-test-db.sql | docker compose exec -T mysql sh -c 'mysql -uroot -p$MYSQL_ROOT_PASSWORD'
```

Git Bash :
```
docker compose exec -T mysql sh -c 'mysql -uroot -p$MYSQL_ROOT_PASSWORD' < ../services/complaint-service/scripts/grant-test-db.sql
```

Le script [scripts/grant-test-db.sql](scripts/grant-test-db.sql) donne les droits sur toutes les bases dont le nom commence par
`complaint_db` (donc `complaint_db` et `complaint_db_test`), puis affiche les droits obtenus :

```sql
GRANT ALL PRIVILEGES ON `complaint_db%`.* TO 'complaint_svc'@'%';
FLUSH PRIVILEGES;
```
