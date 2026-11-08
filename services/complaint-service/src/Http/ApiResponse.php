<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** Réponses JSON lisibles (accents et slashes non échappés) et format d'erreur commun. */
final class ApiResponse
{
    private const ERROR_NAMES = [
        400 => 'BAD_REQUEST',
        401 => 'UNAUTHORIZED',
        403 => 'FORBIDDEN',
        404 => 'NOT_FOUND',
        405 => 'METHOD_NOT_ALLOWED',
        406 => 'NOT_ACCEPTABLE',
        409 => 'CONFLICT',
        415 => 'UNSUPPORTED_MEDIA_TYPE',
        422 => 'UNPROCESSABLE_ENTITY',
        429 => 'TOO_MANY_REQUESTS',
        500 => 'INTERNAL_SERVER_ERROR',
        502 => 'BAD_GATEWAY',
        503 => 'SERVICE_UNAVAILABLE',
    ];

    /** @param array<string, string> $headers */
    public static function json(mixed $data, int $status = Response::HTTP_OK, array $headers = []): JsonResponse
    {
        $response = new JsonResponse(null, $status, $headers);
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $response->setData($data);

        return $response;
    }

    /**
     * {status, error, message, details} (docs/conventions.md).
     *
     * @param list<array{field: string, message: string}> $details
     * @param array<string, string> $headers
     */
    public static function error(int $status, string $message, array $details = [], array $headers = []): JsonResponse
    {
        $body = [
            'status' => $status,
            'error' => self::ERROR_NAMES[$status] ?? strtoupper(str_replace([' ', '-'], '_', Response::$statusTexts[$status] ?? 'ERROR')),
            'message' => $message,
        ];
        if ($details !== []) {
            $body['details'] = $details;
        }

        return self::json($body, $status, $headers);
    }
}
