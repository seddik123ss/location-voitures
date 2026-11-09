<?php

namespace App\Tests\Support;

use Firebase\JWT\JWT;

/**
 * Tokens signés par la clé de test (tests/Fixtures/jwt/test-key.pem), au format des access tokens Keycloak.
 * Les clés de tests/Fixtures/jwt ne servent qu'aux tests : elles ne signent rien en dehors.
 */
final class TestJwt
{
    public const ISSUER = 'http://localhost:8180/realms/location-voitures';
    public const KID = 'test-key';
    public const KEY_FILE = __DIR__ . '/../Fixtures/jwt/test-key.pem';
    public const OTHER_KEY_FILE = __DIR__ . '/../Fixtures/jwt/other-key.pem';

    /**
     * @param array<string, mixed> $claims  remplacent ou complètent les claims par défaut (null retire la claim)
     * @param string               $keyFile clé privée qui signe le token
     */
    public static function token(string $sub, array $roles, array $claims = [], string $keyFile = self::KEY_FILE, string $kid = self::KID): string
    {
        $now = time();
        $payload = array_merge([
            'iss' => self::ISSUER,
            'sub' => $sub,
            'aud' => 'account',
            'azp' => 'gateway',
            'typ' => 'Bearer',
            'iat' => $now,
            'exp' => $now + 300,
            'roles' => array_merge(['default-roles-location-voitures', 'offline_access', 'uma_authorization'], $roles),
        ], $claims);
        $payload = array_filter($payload, static fn (mixed $v): bool => $v !== null);

        return JWT::encode($payload, (string) file_get_contents($keyFile), 'RS256', $kid);
    }

    /** Clé publique de la clé de test, au format JWK (comme dans la réponse /certs de Keycloak). */
    public static function publicJwk(string $keyFile = self::KEY_FILE, string $kid = self::KID): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private((string) file_get_contents($keyFile)));
        $b64 = static fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        return ['kid' => $kid, 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])];
    }
}
