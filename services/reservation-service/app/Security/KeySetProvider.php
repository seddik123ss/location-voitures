<?php

namespace App\Security;

use Firebase\JWT\Key;

/** Clés publiques qui vérifient la signature des tokens, indexées par kid. */
interface KeySetProvider
{
    /** @return array<string, Key> */
    public function keys(bool $refresh = false): array;
}
