<?php

namespace App\Http;

use JsonSerializable;

/**
 * Montant TND écrit dans le JSON comme un nombre à 3 décimales (270.000), comme le veut docs/conventions.md.
 * json_encode() écrirait 270.0 : on encode d'abord un repère, que Json::encode() remplace ensuite par le nombre.
 * Le repère contient un jeton aléatoire propre au processus : une donnée envoyée par un client ne peut pas l'imiter.
 */
final class Amount implements JsonSerializable
{
    private static ?string $marker = null;

    private function __construct(private readonly string $value)
    {
    }

    public static function of(string|int|float|null $value): ?self
    {
        return $value === null ? null : new self(number_format(round((float) $value, 3), 3, '.', ''));
    }

    public static function marker(): string
    {
        return self::$marker ??= 'amount-'.bin2hex(random_bytes(8)).':';
    }

    /** Valeur à 3 décimales, ex. "270.000". */
    public function value(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return self::marker().$this->value;
    }
}
