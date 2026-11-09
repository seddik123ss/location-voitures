<?php

namespace App\Integration\Http;

use App\Exception\ApiException;
use Symfony\Component\Uid\Uuid;

/** Lecture des champs d'une réponse interne : un champ absent ou mal formé rend la réponse illisible (502). */
final class Payload
{
    /** @param array<string, mixed> $data */
    public static function uuid(array $data, string $field, string $app): string
    {
        $value = $data[$field] ?? null;
        if (!is_string($value) || !Uuid::isValid($value)) {
            throw ApiException::badGateway(sprintf('Réponse illisible du service %s : champ %s manquant ou invalide', $app, $field));
        }

        return strtolower($value);
    }

    /** @param array<string, mixed> $data */
    public static function string(array $data, string $field, string $app): string
    {
        $value = $data[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw ApiException::badGateway(sprintf('Réponse illisible du service %s : champ %s manquant ou invalide', $app, $field));
        }

        return $value;
    }
}
