<?php

namespace App\Exception;

/** Erreur métier ou applicative déjà qualifiée : rendue telle quelle au format commun. */
final class ApiException extends \RuntimeException
{
    /** @param list<array{field: string, message: string}> $details */
    public function __construct(public readonly int $status, string $message, public readonly array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** @param list<array{field: string, message: string}> $details */
    public static function badRequest(string $message, array $details = []): self
    {
        return new self(400, $message, $details);
    }

    public static function forbidden(string $message): self
    {
        return new self(403, $message);
    }

    public static function notFound(string $message): self
    {
        return new self(404, $message);
    }

    /** @param list<array{field: string, message: string}> $details */
    public static function conflict(string $message, array $details = []): self
    {
        return new self(409, $message, $details);
    }

    /** @param list<array{field: string, message: string}> $details */
    public static function unprocessable(string $message, array $details = []): self
    {
        return new self(422, $message, $details);
    }

    public static function badGateway(string $message, ?\Throwable $previous = null): self
    {
        return new self(502, $message, [], $previous);
    }

    public static function serviceUnavailable(string $message, ?\Throwable $previous = null): self
    {
        return new self(503, $message, [], $previous);
    }
}
