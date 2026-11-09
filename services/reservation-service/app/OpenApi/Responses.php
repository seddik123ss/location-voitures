<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/** Réponses d'erreur réutilisables (components/responses), avec exemples. */
#[OA\Response(response: 'BadRequest', description: 'Requête invalide (champs dans details)', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: [
    'status' => 400, 'error' => 'BAD_REQUEST', 'message' => 'Requête invalide',
    'details' => [['field' => 'endDate', 'message' => 'endDate doit être après startDate (au moins un jour de location)']],
]))]
#[OA\Response(response: 'Unauthorized', description: 'Token absent, invalide, mal signé, d\'un autre émetteur ou expiré', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: [
    'status' => 401, 'error' => 'UNAUTHORIZED', 'message' => 'Token absent : en-tête Authorization: Bearer <token> attendu',
]))]
#[OA\Response(response: 'Forbidden', description: 'Rôle insuffisant ou réservation d\'un autre client', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: [
    'status' => 403, 'error' => 'FORBIDDEN', 'message' => 'Cette réservation appartient à un autre client',
]))]
#[OA\Response(response: 'NotFound', description: 'Réservation introuvable', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: [
    'status' => 404, 'error' => 'NOT_FOUND', 'message' => 'Réservation introuvable',
]))]
#[OA\Response(response: 'TransitionConflict', description: 'Transition de statut interdite (transitions possibles dans details)', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: [
    'status' => 409, 'error' => 'CONFLICT', 'message' => 'Transition interdite : PENDING -> COMPLETED',
    'details' => [['field' => 'status', 'message' => 'Transitions possibles depuis PENDING : CONFIRMED, CANCELLED']],
]))]
#[OA\Response(response: 'BadGateway', description: 'Réponse illisible de user-service ou vehicle-service', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: [
    'status' => 502, 'error' => 'BAD_GATEWAY', 'message' => 'Réponse illisible du service RENTAL-VEHICLE (JSON invalide)',
]))]
#[OA\Response(response: 'ServiceUnavailable', description: 'Service appelé, Eureka, Keycloak ou RabbitMQ indisponible', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: [
    'status' => 503, 'error' => 'SERVICE_UNAVAILABLE', 'message' => 'Service RENTAL-USER indisponible',
]))]
final class Responses
{
}
