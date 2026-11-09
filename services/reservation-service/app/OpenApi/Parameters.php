<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/** Paramètres réutilisables (components/parameters). Classe séparée : swagger-php y imbriquerait sinon les schémas. */
#[OA\Parameter(parameter: 'ReservationId', name: 'id', description: 'UUID de la réservation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'), example: '33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8')]
#[OA\Parameter(parameter: 'Page', name: 'page', description: 'Numéro de page, à partir de 0', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 0, minimum: 0))]
#[OA\Parameter(parameter: 'Size', name: 'size', description: 'Taille de page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, maximum: 100, minimum: 1))]
#[OA\Parameter(parameter: 'CorrelationId', name: 'X-Correlation-Id', description: 'Repris tel quel dans la réponse et transmis aux autres services (généré s\'il manque)', in: 'header', required: false, schema: new OA\Schema(type: 'string'), example: 'demo-42')]
final class Parameters
{
}
