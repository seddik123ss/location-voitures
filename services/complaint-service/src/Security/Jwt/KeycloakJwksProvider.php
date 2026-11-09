<?php

namespace App\Security\Jwt;

use App\Exception\ApiException;
use Firebase\JWT\JWK;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Clés JWKS du realm, gardées en cache (cache.app) pendant une heure. Adresse : KEYCLOAK_JWKS si renseignée (en Docker,
 * http://keycloak:8080/... alors que l'émetteur reste http://localhost:8180/...), sinon {issuer}/protocol/openid-connect/certs.
 * Seules les clés RSA de signature (use = sig, RS256) sont retenues : Keycloak publie aussi une clé de chiffrement RSA-OAEP.
 * Un rechargement forcé (kid inconnu) est limité à un par minute, pour qu'un token forgé ne fasse pas appeler Keycloak à chaque requête.
 */
final class KeycloakJwksProvider implements KeySetProvider
{
    private const CACHE_TTL = 3600;
    private const REFRESH_INTERVAL = 60;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'KEYCLOAK_ISSUER')] private readonly string $issuer,
        #[Autowire(env: 'KEYCLOAK_JWKS')] private readonly string $jwksUrl = '',
    ) {
    }

    public function keys(bool $refresh = false): array
    {
        $key = 'keycloak_jwks_' . md5($this->issuer . '|' . $this->jwksUrl);
        if ($refresh && $this->mayRefresh($key)) {
            $this->cache->delete($key);
        }

        $jwks = $this->cache->get($key, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->fetch();
        });

        return JWK::parseKeySet(['keys' => $jwks], 'RS256');
    }

    /** Au plus un rechargement forcé par minute. */
    private function mayRefresh(string $key): bool
    {
        $stampKey = $key . '_refreshed';
        $last = $this->cache->get($stampKey, static fn (): int => 0);
        if (time() - $last < self::REFRESH_INTERVAL) {
            return false;
        }
        $this->cache->delete($stampKey);
        $this->cache->get($stampKey, static function (ItemInterface $item): int {
            $item->expiresAfter(self::REFRESH_INTERVAL);

            return time();
        });

        return true;
    }

    /** @return list<array<string, mixed>> clés de signature au format JWK */
    private function fetch(): array
    {
        $url = $this->jwksUrl !== '' ? $this->jwksUrl : rtrim($this->issuer, '/') . '/protocol/openid-connect/certs';
        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 3, 'max_duration' => 5]);
            $status = $response->getStatusCode();
            $data = $status === 200 ? $response->toArray(false) : null;
        } catch (HttpException $e) {
            $this->logger->error(sprintf('Clés JWKS injoignables (%s) : %s', $url, $e->getMessage()));
            throw ApiException::serviceUnavailable('Keycloak injoignable : clés de signature des tokens indisponibles', $e);
        }
        if ($status !== 200 || !is_array($data['keys'] ?? null)) {
            $this->logger->error(sprintf('Réponse JWKS inattendue de %s (HTTP %d)', $url, $status));
            throw ApiException::serviceUnavailable('Keycloak injoignable : clés de signature des tokens indisponibles');
        }

        $keys = array_values(array_filter($data['keys'], static fn (mixed $k): bool => is_array($k)
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
