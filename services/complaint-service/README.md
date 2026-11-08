# complaint-service

Réclamations des clients : Symfony 7.4, PHP 8.5, MySQL `complaint_db`, port 8106, nom Eureka `RENTAL-COMPLAINT`.

> README en cours : il sera complété à l'étape README (lancement, configuration, Eureka, Docker).

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
