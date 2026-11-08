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
http://localhost:8761. Arrêtez `composer eureka` avec **Ctrl+C** : la commande se retire d'Eureka avant de quitter, et la Gateway
cesse aussitôt de router vers le service (503). Si le terminal a été fermé sans Ctrl+C, retirez l'instance avec
`php bin/console app:eureka:deregister` ; sinon l'auto-préservation d'Eureka peut la garder affichée.

Vérification via la Gateway (http://localhost:8088) : `GET /api/complaints` sans token donne 401 (Gateway). Avec un token Keycloak
**et**, tant que l'étape Sécurité n'est pas faite, les en-têtes `X-Dev-User-Id` / `X-Dev-Roles`, on obtient 200.

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

En test, l'appelant est donné par les en-têtes de développement `X-Dev-User-Id` et `X-Dev-Roles`.

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
