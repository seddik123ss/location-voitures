<?php

namespace App\Security\Jwt;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Vérifie un access token Keycloak : signature RS256 avec les clés du realm, émetteur, expiration (obligatoire), sub (UUID).
 */
final class KeycloakTokenDecoder
{
    /** Tolérance sur les dates (horloges du conteneur Keycloak et de la machine). */
    public const LEEWAY_SECONDS = 10;

    public function __construct(
        private readonly KeySetProvider $keySet,
        #[Autowire(env: 'KEYCLOAK_ISSUER')] private readonly string $issuer,
    ) {
    }

    /**
     * @return array<string, mixed> claims du token
     *
     * @throws InvalidToken
     */
    public function decode(string $jwt): array
    {
        $kid = $this->kid($jwt);
        $keys = $this->keySet->keys();
        if (!isset($keys[$kid])) {
            $keys = $this->keySet->keys(refresh: true);
        }
        if (!isset($keys[$kid])) {
            throw new InvalidToken('Token signé par une clé inconnue');
        }

        JWT::$leeway = self::LEEWAY_SECONDS;
        try {
            $claims = (array) JWT::decode($jwt, $keys);
        } catch (ExpiredException) {
            throw new InvalidToken('Token expiré');
        } catch (SignatureInvalidException) {
            throw new InvalidToken('Signature du token invalide');
        } catch (BeforeValidException) {
            throw new InvalidToken('Token pas encore valide');
        } catch (\UnexpectedValueException|\DomainException|\InvalidArgumentException) {
            throw new InvalidToken('Token invalide');
        }

        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new InvalidToken('Émetteur du token invalide');
        }
        if (!is_int($claims['exp'] ?? null)) {
            throw new InvalidToken("Token sans date d'expiration");
        }
        if (isset($claims['typ']) && $claims['typ'] !== 'Bearer') {
            throw new InvalidToken('Un access token est attendu');
        }
        if (!is_string($claims['sub'] ?? null) || !Uuid::isValid($claims['sub'])) {
            throw new InvalidToken("Token sans identifiant d'utilisateur (sub)");
        }

        return $claims;
    }

    private function kid(string $jwt): string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new InvalidToken('Token invalide');
        }
        try {
            $header = JWT::jsonDecode(JWT::urlsafeB64Decode($parts[0]));
        } catch (\Throwable) {
            throw new InvalidToken('Token invalide');
        }
        if (!is_object($header) || !is_string($header->kid ?? null) || ($header->alg ?? null) !== 'RS256') {
            throw new InvalidToken('Token invalide : signature RS256 avec kid attendue');
        }

        return $header->kid;
    }
}
