<?php

/*
 * Enregistrement dans Eureka avec shared/php-eureka (docs/conventions.md, « Adresse annoncée dans Eureka »).
 * Le service s'annonce sous host.docker.internal : ce nom fonctionne depuis Windows et depuis la Gateway (Docker).
 */
return [
    'url' => env('EUREKA_URL', 'http://localhost:8761/eureka'),
    'app' => 'RENTAL-RESERVATION',
    'host' => env('SERVICE_HOST', 'host.docker.internal'),
    'port' => (int) env('SERVICE_PORT', 8103),
    // Secondes entre deux battements (Eureka retire une instance sans battement après 90 s).
    'heartbeat_interval' => 30,
];
