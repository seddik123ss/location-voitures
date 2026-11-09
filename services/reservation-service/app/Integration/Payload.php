<?php

namespace App\Integration;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Lecture stricte des champs d'une réponse JSON d'un autre service. */
final class Payload
{
    public static function uuid(array $data, string $field): string
    {
        $value = $data[$field] ?? null;
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw new InvalidPayload("Champ $field absent ou qui n'est pas un UUID");
        }

        return strtolower($value);
    }

    public static function string(array $data, string $field): string
    {
        $value = $data[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidPayload("Champ $field absent ou vide");
        }

        return $value;
    }

    public static function bool(array $data, string $field): bool
    {
        $value = $data[$field] ?? null;
        if (! is_bool($value)) {
            throw new InvalidPayload("Champ $field absent ou qui n'est pas un booléen");
        }

        return $value;
    }

    public static function date(array $data, string $field): CarbonImmutable
    {
        $value = $data[$field] ?? null;
        $date = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC')
            : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidPayload("Champ $field absent ou qui n'est pas une date YYYY-MM-DD");
        }

        return $date;
    }

    /** Montant (nombre JSON, ou chaîne décimale) en chaîne à 3 décimales. */
    public static function amount(array $data, string $field): string
    {
        $value = $data[$field] ?? null;
        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }
        if (! is_int($value) && ! is_float($value) || $value < 0) {
            throw new InvalidPayload("Champ $field absent ou qui n'est pas un montant positif");
        }

        return number_format(round((float) $value, 3), 3, '.', '');
    }

    /** @return array<string, mixed> */
    public static function object(array $data, string $field): array
    {
        $value = $data[$field] ?? null;
        if (! is_array($value) || array_is_list($value) && $value !== []) {
            throw new InvalidPayload("Champ $field absent ou qui n'est pas un objet");
        }

        return $value;
    }
}
