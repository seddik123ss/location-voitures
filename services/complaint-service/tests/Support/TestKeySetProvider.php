<?php

namespace App\Tests\Support;

use App\Security\Jwt\KeySetProvider;
use Firebase\JWT\JWK;

/** Environnement de test (config/services.yaml, when@test) : la clé publique de test à la place des clés de Keycloak. */
final class TestKeySetProvider implements KeySetProvider
{
    public function keys(bool $refresh = false): array
    {
        return JWK::parseKeySet(['keys' => [TestJwt::publicJwk()]]);
    }
}
