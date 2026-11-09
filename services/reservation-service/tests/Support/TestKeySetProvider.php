<?php

namespace Tests\Support;

use App\Security\KeySetProvider;
use Firebase\JWT\JWK;

/** Remplace le JWKS de Keycloak par la clé publique de test. */
final class TestKeySetProvider implements KeySetProvider
{
    public function keys(bool $refresh = false): array
    {
        return JWK::parseKeySet(['keys' => [TestJwt::publicJwk()]]);
    }
}
