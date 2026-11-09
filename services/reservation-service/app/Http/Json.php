<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;

/** Réponses JSON du service : accents et / non échappés, montants écrits avec 3 décimales (270.000). */
final class Json
{
    public const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public static function encode(mixed $data): string
    {
        $json = json_encode($data, self::FLAGS);

        // "amount-<jeton>:270.000" -> 270.000 (nombre JSON)
        return preg_replace('/"'.preg_quote(Amount::marker(), '/').'(-?\d+\.\d{3})"/', '$1', $json);
    }

    public static function response(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse(self::encode($data), $status, $headers, 0, json: true);
    }

    /**
     * Liste paginée commune : {"items": [...], "page": 0, "size": 20, "total": 57}.
     *
     * @param list<mixed> $items
     */
    public static function page(array $items, int $page, int $size, int $total): JsonResponse
    {
        return self::response(['items' => $items, 'page' => $page, 'size' => $size, 'total' => $total]);
    }

    /** Montant TND, écrit 270.000 dans la réponse. */
    public static function amount(string|int|float|null $value): ?Amount
    {
        return Amount::of($value);
    }
}
