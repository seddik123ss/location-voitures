<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Définitions communes du contrat OpenAPI (Swagger UI : /api/doc).
 * Les opérations sont décrites sur les méthodes des contrôleurs. Export partagé : composer openapi:dump
 * -> docs/openapi/reservation-service.yaml.
 */
#[OA\Info(
    version: '1.0.0',
    title: 'reservation-service',
    description: "Réservations de véhicules de la plateforme de location (module d'Aziz, Laravel).\n\n"
        ."Un client réserve un véhicule sur une période : le service vérifie son permis (user-service) et le véhicule "
        ."(vehicle-service), calcule le prix (jours x dailyRate de la catégorie), puis suit la réservation jusqu'à la fin "
        ."de la location.\n\n"
        ."Rôles (claim `roles` du token Keycloak, realm location-voitures) : CLIENT ne voit et ne gère que ses réservations ; "
        ."AGENT et ADMIN voient tout et changent les statuts ; ADMIN supprime ; INTERNAL (comptes de service) pour /internal/**.\n\n"
        ."Formats communs (docs/conventions.md) : JSON camelCase, UUID v4, dates YYYY-MM-DD, instants ISO 8601 UTC, "
        ."montants à 3 décimales en TND, erreurs {status, error, message, details}, en-tête X-Correlation-Id.",
)]
#[OA\Server(url: 'http://localhost:8088', description: 'via la Gateway (routes /api/reservations/**)')]
#[OA\Server(url: 'http://localhost:8103', description: 'direct (développement ; seul accès aux routes /internal)')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'Access token Keycloak (realm location-voitures). Utilisateur : client rental-front ; service : client credentials.',
)]
#[OA\Tag(name: 'Réservations', description: 'API publique, via la Gateway (rôles CLIENT, AGENT, ADMIN)')]
#[OA\Tag(name: 'Interne', description: 'Routes pour les autres microservices (token de compte de service, rôle INTERNAL), jamais exposées par la Gateway')]
#[OA\Tag(name: 'Santé', description: 'État du service')]
final class OpenApiSpec
{
}
