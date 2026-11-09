<?php

namespace Tests\Support;

use Firebase\JWT\JWT;

/** Fabrique des access tokens au format Keycloak, signés par la clé de test. */
final class TestJwt
{
    public const ISSUER = 'http://localhost:8180/realms/location-voitures';
    public const KID = 'test-key';
    public const KEY_FILE = __DIR__.'/../Fixtures/jwt/test-key.pem';
    public const OTHER_KEY_FILE = __DIR__.'/../Fixtures/jwt/other-key.pem';

    /** sub du compte de service d'un autre service (ex. service-account-payment-service). */
    public const SERVICE_ACCOUNT = '7d3c1a52-6c3e-4b8e-9d0a-2f1e5b6c7a80';

    /**
     * @param list<string> $roles rôles du realm (claim "roles")
     * @param array<string, mixed> $claims claims à remplacer ; null retire le claim
     */
    public static function token(string $sub, array $roles, array $claims = [], string $keyFile = self::KEY_FILE, string $kid = self::KID): string
    {
        $now = time();
        $payload = array_merge([
            'iss' => self::ISSUER,
            'sub' => $sub,
            'aud' => 'account',
            'azp' => 'payment-service',
            'typ' => 'Bearer',
            'iat' => $now,
            'exp' => $now + 300,
            'roles' => array_merge(['default-roles-location-voitures', 'offline_access', 'uma_authorization'], $roles),
        ], $claims);
        $payload = array_filter($payload, static fn (mixed $v): bool => $v !== null);

        return JWT::encode($payload, (string) file_get_contents($keyFile), 'RS256', $kid);
    }

    /** Token de compte de service avec le rôle INTERNAL. */
    public static function internal(array $claims = []): string
    {
        return self::token(self::SERVICE_ACCOUNT, ['INTERNAL'], $claims);
    }

    /** @return array<string, string> clé publique au format JWKS */
    public static function publicJwk(string $keyFile = self::KEY_FILE, string $kid = self::KID): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private((string) file_get_contents($keyFile)));
        $b64 = static fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        return ['kid' => $kid, 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])];
    }
}
