<?php

namespace App\Integration;

/** Réponse d'un autre service sans le champ attendu ou mal formée (traduite en 502). */
final class InvalidPayload extends \RuntimeException
{
}
