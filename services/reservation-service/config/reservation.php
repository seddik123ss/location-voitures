<?php

return [
    /*
     * Appels à user-service et vehicle-service :
     * - demo   : données de docs/demo-ids.md, sans appel réseau (par défaut, tant que les autres services ne tournent pas) ;
     * - eureka : vrais appels GET /internal/... via Eureka, avec le token du compte de service reservation-service.
     */
    'lookup_mode' => env('LOOKUP_MODE', 'demo'),

    // Fuseau pour « aujourd'hui » dans la règle startDate >= aujourd'hui (les agences sont en Tunisie).
    'timezone' => 'Africa/Tunis',

    'currency' => 'TND',

    // Délais des appels entre services (secondes).
    'http_connect_timeout' => 3,
    'http_timeout' => 5,
];
