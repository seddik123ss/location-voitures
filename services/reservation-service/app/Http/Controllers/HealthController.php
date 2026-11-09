<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class HealthController
{
    /** GET /health : utilisé par Eureka (healthCheckUrl) et par la Gateway. */
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'UP']);
    }
}
