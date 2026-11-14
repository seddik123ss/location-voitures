# shared/php-eureka

Client Eureka partagé par les deux services PHP : complaint-service (Symfony, Seddik) et reservation-service (Laravel, Aziz).
Il utilise l'API REST d'Eureka avec cURL (extension `curl` de PHP requise).

## Brancher dans un service

Dans le `composer.json` du service, section `autoload.psr-4` :

```json
"Rental\\Eureka\\": "../../shared/php-eureka/src/"
```

puis `composer dump-autoload`.

Variables d'environnement du service : `EUREKA_URL=http://localhost:8761/eureka`, `SERVICE_PORT`.

## Enregistrer le service (commande console lancée à côté du serveur web)

Symfony (`php bin/console app:eureka:run`) :

```php
use Rental\Eureka\EurekaClient;
use Rental\Eureka\EurekaRunner;

EurekaRunner::run(
    new EurekaClient($_ENV['EUREKA_URL'], 'RENTAL-COMPLAINT', (int) $_ENV['SERVICE_PORT']),
    fn (string $m) => $output->writeln($m)
);
```

Laravel (`php artisan eureka:run`) : même code avec `'RENTAL-RESERVATION'` et `$this->info($m)`.

Paramètres facultatifs : `$host` (nom annoncé, par défaut `SERVICE_HOST`) et `$statusPagePath` (lien affiché dans le tableau
de bord Eureka, `/health` par défaut ; complaint et reservation passent `/api/doc` pour ouvrir leur Swagger UI).

## Appeler un autre service

```php
$base = $eureka->resolve('RENTAL-VEHICLE');   // http://<ip>:8102
// puis GET $base . '/internal/vehicles/' . $id avec le token du compte de service
```

`EurekaUnavailable` (Eureka injoignable ou aucune instance UP) se traduit en réponse 503, comme dans le workshop OpenFeign.
Objet distant introuvable (404 du service appelé) -> 404 ; réponse illisible -> 502.

## Tester sans service

`php shared/php-eureka/try.php` depuis la racine du dépôt : `RENTAL-TEST` apparaît sur http://localhost:8761 pendant 40 secondes, puis disparaît.
