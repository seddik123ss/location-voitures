<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;

/** Réponses JSON du service : accents et / non échappés, montants toujours écrits en décimal (450.0, pas 450). */
final class Json
{
    public const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public static function response(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers, self::FLAGS);
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

    /** Montant TND en nombre JSON, arrondi à 3 décimales. */
    public static function amount(string|int|float|null $value): ?float
    {
        return $value === null ? null : round((float) $value, 3);
    }
}
