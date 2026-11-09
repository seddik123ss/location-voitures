<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

final class HealthController
{
    /** GET /health : utilisé par Eureka (healthCheckUrl) et par la Gateway. */
    #[OA\Get(
        path: '/health',
        operationId: 'health',
        summary: 'État du service',
        description: 'Public. Utilisé par Eureka (healthCheckUrl).',
        security: [],
        tags: ['Santé'],
        responses: [new OA\Response(response: 200, description: 'Service démarré', content: new OA\JsonContent(properties: [new OA\Property(property: 'status', type: 'string', example: 'UP')], type: 'object'))],
    )]
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'UP']);
    }
}
