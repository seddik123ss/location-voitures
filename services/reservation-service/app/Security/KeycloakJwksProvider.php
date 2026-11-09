<?php

namespace App\Security;

use App\Exceptions\ApiException;
use Firebase\JWT\JWK;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Clés publiques du realm (JWKS de Keycloak), gardées en cache.
 * Si un token arrive avec un kid inconnu (rotation des clés), le cache est rechargé, au plus une fois par minute
 * pour qu'un flot de tokens forgés ne fasse pas tomber Keycloak.
 */
final class KeycloakJwksProvider implements KeySetProvider
{
    public function keys(bool $refresh = false): array
    {
        $cacheKey = 'keycloak_jwks_'.md5(config('keycloak.issuer').'|'.$this->url());
        if ($refresh && $this->mayRefresh($cacheKey)) {
            Cache::forget($cacheKey);
        }

        $jwks = Cache::remember($cacheKey, (int) config('keycloak.jwks_cache_ttl'), fn (): array => $this->fetch());

        return JWK::parseKeySet(['keys' => $jwks], 'RS256');
    }

    private function url(): string
    {
        return config('keycloak.jwks_url') ?? rtrim((string) config('keycloak.issuer'), '/').'/protocol/openid-connect/certs';
    }

    private function mayRefresh(string $cacheKey): bool
    {
        // add() n'écrit que si la clé est absente : un seul rechargement par intervalle.
        return Cache::add($cacheKey.'_refreshed', time(), (int) config('keycloak.jwks_refresh_interval'));
    }

    /** @return list<array<string, mixed>> */
    private function fetch(): array
    {
        $url = $this->url();
        try {
            $response = Http::timeout(5)->connectTimeout(3)->acceptJson()->get($url);
        } catch (ConnectionException $e) {
            Log::error("Clés JWKS injoignables ($url) : ".$e->getMessage());
            throw ApiException::serviceUnavailable('Keycloak injoignable : clés de signature des tokens indisponibles', $e);
        }

        $keys = $response->status() === 200 ? $response->json('keys') : null;
        if (! is_array($keys)) {
            Log::error("Réponse JWKS inattendue de $url (HTTP {$response->status()})");
            throw ApiException::serviceUnavailable('Keycloak injoignable : clés de signature des tokens indisponibles');
        }

        // Seulement les clés RSA de signature : Keycloak publie aussi une clé de chiffrement (RSA-OAEP).
        $keys = array_values(array_filter($keys, static fn (mixed $k): bool => is_array($k)
            && ($k['kty'] ?? null) === 'RSA'
            && is_string($k['kid'] ?? null)
            && ($k['use'] ?? 'sig') === 'sig'
            && ($k['alg'] ?? 'RS256') === 'RS256'));
        if ($keys === []) {
            throw ApiException::serviceUnavailable('Keycloak ne publie aucune clé de signature RS256');
        }

        return $keys;
    }
}
