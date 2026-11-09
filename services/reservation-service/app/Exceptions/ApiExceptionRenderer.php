<?php

namespace App\Exceptions;

use App\Http\Middleware\CorrelationId;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Traduit toute exception en réponse JSON au format commun (docs/conventions.md). */
final class ApiExceptionRenderer
{
    private const ERRORS = [
        400 => 'BAD_REQUEST',
        401 => 'UNAUTHORIZED',
        403 => 'FORBIDDEN',
        404 => 'NOT_FOUND',
        405 => 'METHOD_NOT_ALLOWED',
        409 => 'CONFLICT',
        415 => 'UNSUPPORTED_MEDIA_TYPE',
        422 => 'UNPROCESSABLE_ENTITY',
        429 => 'TOO_MANY_REQUESTS',
        500 => 'INTERNAL_SERVER_ERROR',
        502 => 'BAD_GATEWAY',
        503 => 'SERVICE_UNAVAILABLE',
    ];

    public static function render(Throwable $e): JsonResponse
    {
        [$status, $error, $message, $details] = match (true) {
            $e instanceof ApiException => [$e->status, $e->error, $e->getMessage(), $e->details],
            // Champs invalides : 400 (le 422 est réservé aux règles métier).
            $e instanceof ValidationException => [400, 'BAD_REQUEST', 'Requête invalide', self::validationDetails($e)],
            $e instanceof ModelNotFoundException => [404, 'NOT_FOUND', 'Ressource introuvable', []],
            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(),
                self::ERRORS[$e->getStatusCode()] ?? 'ERROR',
                self::httpMessage($e),
                [],
            ],
            default => [500, 'INTERNAL_SERVER_ERROR', 'Erreur interne du service', []],
        };

        if ($status >= 500) {
            report($e);
        }

        $body = ['status' => $status, 'error' => $error, 'message' => $message];
        if ($details !== []) {
            $body['details'] = $details;
        }

        return new JsonResponse($body, $status, [CorrelationId::HEADER => CorrelationId::current()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<array{field: string, message: string}> */
    private static function validationDetails(ValidationException $e): array
    {
        $details = [];
        foreach ($e->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $details[] = ['field' => $field, 'message' => $message];
            }
        }

        return $details;
    }

    private static function httpMessage(HttpExceptionInterface $e): string
    {
        return match ($e->getStatusCode()) {
            404 => 'Ressource introuvable',
            405 => 'Méthode non autorisée sur cette route',
            default => $e->getMessage() !== '' ? $e->getMessage() : 'Erreur HTTP '.$e->getStatusCode(),
        };
    }
}
