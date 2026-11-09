<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Erreur métier ou technique traduite en réponse au format commun :
 * {"status": 409, "error": "CONFLICT", "message": "...", "details": [{"field", "message"}]}.
 */
class ApiException extends RuntimeException
{
    /** @param list<array{field: string, message: string}> $details */
    public function __construct(
        public readonly int $status,
        public readonly string $error,
        string $message,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function badRequest(string $message, array $details = []): self
    {
        return new self(400, 'BAD_REQUEST', $message, $details);
    }

    public static function unauthorized(string $message = 'Authentification requise'): self
    {
        return new self(401, 'UNAUTHORIZED', $message);
    }

    public static function forbidden(string $message = 'Accès refusé'): self
    {
        return new self(403, 'FORBIDDEN', $message);
    }

    public static function notFound(string $message): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }

    public static function conflict(string $message, array $details = []): self
    {
        return new self(409, 'CONFLICT', $message, $details);
    }

    public static function unprocessable(string $message, array $details = []): self
    {
        return new self(422, 'UNPROCESSABLE_ENTITY', $message, $details);
    }

    public static function badGateway(string $message, ?Throwable $previous = null): self
    {
        return new self(502, 'BAD_GATEWAY', $message, [], $previous);
    }

    public static function serviceUnavailable(string $message, ?Throwable $previous = null): self
    {
        return new self(503, 'SERVICE_UNAVAILABLE', $message, [], $previous);
    }
}
