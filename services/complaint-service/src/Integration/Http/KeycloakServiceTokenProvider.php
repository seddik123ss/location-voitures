<?php

namespace App\Integration\Http;

use App\Exception\ApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client credentials auprès de Keycloak (docs/internal-api.md), jeton gardé en cache jusqu'à 30 s avant son expiration.
 * Adresse : KEYCLOAK_TOKEN_URL si renseignée (Docker : http://keycloak:8080/...), sinon {issuer}/protocol/openid-connect/token.
 * Le cache (cache.app, sur disque) est partagé entre les requêtes : un seul appel à Keycloak toutes les ~4 min 30.
 */
final class KeycloakServiceTokenProvider implements ServiceTokenProvider
{
    private const CACHE_KEY = 'keycloak_service_token';
    private const MARGIN_SECONDS = 30;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'KEYCLOAK_ISSUER')] private readonly string $issuer,
        #[Autowire(env: 'KEYCLOAK_CLIENT_ID')] private readonly string $clientId,
        #[Autowire(env: 'KEYCLOAK_CLIENT_SECRET')] private readonly string $clientSecret,
        #[Autowire(env: 'KEYCLOAK_TOKEN_URL')] private readonly string $tokenUrl = '',
    ) {
    }

    public function token(): string
    {
        return $this->cache->get(self::CACHE_KEY . '_' . md5($this->issuer . $this->clientId), function (ItemInterface $item): string {
            [$token, $expiresIn] = $this->fetch();
            $item->expiresAfter(max(1, $expiresIn - self::MARGIN_SECONDS));

            return $token;
        });
    }

    public function invalidate(): void
    {
        $this->cache->delete(self::CACHE_KEY . '_' . md5($this->issuer . $this->clientId));
    }

    /** @return array{string, int} jeton et durée de validité en secondes */
    private function fetch(): array
    {
        if ($this->clientSecret === '') {
            throw new \LogicException('KEYCLOAK_CLIENT_SECRET est vide : renseigner le secret du client Keycloak complaint-service (voir README).');
        }
        $url = $this->tokenUrl !== '' ? $this->tokenUrl : rtrim($this->issuer, '/') . '/protocol/openid-connect/token';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'body' => ['grant_type' => 'client_credentials', 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret],
                'timeout' => 3,
                'max_duration' => 5,
            ]);
            $status = $response->getStatusCode();
            $data = $status === 200 ? $response->toArray(false) : null;
        } catch (HttpException $e) {
            $this->logger->error('Keycloak injoignable : ' . $e->getMessage());
            throw ApiException::serviceUnavailable('Keycloak injoignable : jeton de service impossible à obtenir', $e);
        }

        if ($status !== 200) {
            $this->logger->error(sprintf('Keycloak a refusé le client %s (HTTP %d)', $this->clientId, $status));
            throw ApiException::serviceUnavailable(sprintf('Jeton de service refusé par Keycloak (HTTP %d)', $status));
        }
        if (!is_string($data['access_token'] ?? null) || !is_int($data['expires_in'] ?? null)) {
            throw ApiException::serviceUnavailable('Réponse de Keycloak sans access_token');
        }

        return [$data['access_token'], $data['expires_in']];
    }
}
