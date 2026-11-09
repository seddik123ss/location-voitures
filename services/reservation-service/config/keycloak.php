<?php

/*
 * Keycloak, realm location-voitures (docs/conventions.md).
 * L'émetteur reste http://localhost:8180/... même quand le service tourne dans Docker : on peut alors joindre
 * Keycloak par une autre adresse avec KEYCLOAK_JWKS et KEYCLOAK_TOKEN_URL (vides = déduites de l'émetteur).
 */
return [
    'issuer' => env('KEYCLOAK_ISSUER', 'http://localhost:8180/realms/location-voitures'),
    'jwks_url' => env('KEYCLOAK_JWKS') ?: null,
    'token_url' => env('KEYCLOAK_TOKEN_URL') ?: null,
    'client_id' => env('KEYCLOAK_CLIENT_ID', 'reservation-service'),
    'client_secret' => env('KEYCLOAK_CLIENT_SECRET', ''),

    // Tolérance d'horloge sur exp / iat / nbf, en secondes.
    'leeway' => 10,
    // Durée de cache des clés publiques ; une clé inconnue (rotation) force un rechargement, au plus une fois par minute.
    'jwks_cache_ttl' => 3600,
    'jwks_refresh_interval' => 60,
];
