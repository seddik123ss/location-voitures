<?php

namespace App\Controller;

use App\Http\ApiResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    #[OA\Get(
        summary: 'État du service',
        description: 'Public, sans authentification.',
        security: [],
        tags: ['Santé'],
        responses: [new OA\Response(response: 200, description: 'Service démarré', content: new OA\JsonContent(ref: '#/components/schemas/Health'))],
    )]
    public function __invoke(): JsonResponse
    {
        return ApiResponse::json(['status' => 'UP']);
    }
}
