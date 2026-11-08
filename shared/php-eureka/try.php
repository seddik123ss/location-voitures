<?php
// Test rapide sans service : php shared/php-eureka/try.php (Eureka doit tourner sur 8761)
require __DIR__ . '/src/EurekaUnavailable.php';
require __DIR__ . '/src/EurekaClient.php';

$c = new Rental\Eureka\EurekaClient('http://localhost:8761/eureka', 'RENTAL-TEST', 9999);
$c->register();
echo 'Enregistré : ' . $c->instanceId() . PHP_EOL;
echo 'Vérifiez http://localhost:8761 (40 secondes)...' . PHP_EOL;
sleep(40);
$c->heartbeat();
echo 'Résolu : ' . $c->resolve('RENTAL-TEST') . PHP_EOL;
$c->deregister();
echo 'Retiré' . PHP_EOL;
