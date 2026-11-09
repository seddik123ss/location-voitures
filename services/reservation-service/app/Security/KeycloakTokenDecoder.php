<?php

namespace App\Security;

use App\Exceptions\ApiException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Str;

/**
 * Vérifie un access token Keycloak : signature RS256 avec une clé du JWKS (kid), expiration, émetteur,
 * type Bearer et sub, puis renvoie l'appelant avec ses rôles du realm (claim "roles").
 */
final class KeycloakTokenDecoder
{
    public function __construct(private readonly KeySetProvider $keySet)
    {
    }

    public function decode(string $jwt): Principal
    {
        $kid = $this->kid($jwt);
        $keys = $this->keySet->keys();
        if (! isset($keys[$kid])) {
            $keys = $this->keySet->keys(refresh: true);
        }
        if (! isset($keys[$kid])) {
            throw ApiException::invalidToken('Token signé par une clé inconnue');
        }

        JWT::$leeway = (int) config('keycloak.leeway');
        try {
            $claims = (array) JWT::decode($jwt, $keys);
        } catch (ExpiredException) {
            throw ApiException::invalidToken('Token expiré');
        } catch (SignatureInvalidException) {
            throw ApiException::invalidToken('Signature du token invalide');
        } catch (BeforeValidException) {
            throw ApiException::invalidToken('Token pas encore valide');
        } catch (\UnexpectedValueException|\DomainException|\InvalidArgumentException) {
            throw ApiException::invalidToken('Token invalide');
        }

        if (($claims['iss'] ?? null) !== config('keycloak.issuer')) {
            throw ApiException::invalidToken('Émetteur du token invalide');
        }
        if (! is_int($claims['exp'] ?? null)) {
            throw ApiException::invalidToken("Token sans date d'expiration");
        }
        if (isset($claims['typ']) && $claims['typ'] !== 'Bearer') {
            throw ApiException::invalidToken('Un access token est attendu');
        }
        if (! is_string($claims['sub'] ?? null) || ! Str::isUuid($claims['sub'])) {
            throw ApiException::invalidToken("Token sans identifiant d'utilisateur (sub)");
        }

        $roles = is_array($claims['roles'] ?? null)
            ? array_values(array_intersect(Principal::REALM_ROLES, $claims['roles']))
            : [];

        return new Principal(strtolower($claims['sub']), $roles, is_string($claims['azp'] ?? null) ? $claims['azp'] : null);
    }

    private function kid(string $jwt): string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw ApiException::invalidToken('Token invalide');
        }
        try {
            $header = JWT::jsonDecode(JWT::urlsafeB64Decode($parts[0]));
        } catch (\Throwable) {
            throw ApiException::invalidToken('Token invalide');
        }
        // L'algorithme est imposé (RS256) : un token "alg": "none" ou HS256 est refusé avant toute vérification.
        if (! is_object($header) || ! is_string($header->kid ?? null) || ($header->alg ?? null) !== 'RS256') {
            throw ApiException::invalidToken('Token invalide : signature RS256 avec kid attendue');
        }

        return $header->kid;
    }
}
