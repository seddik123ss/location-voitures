<?php

namespace App\Dto;

/** Normaliseurs des contraintes de validation (sûrs quel que soit le type reçu). */
final class Normalize
{
    /** "  " devient "" pour que NotBlank refuse un texte fait d'espaces ; les autres types passent tels quels. */
    public static function trim(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }
}
