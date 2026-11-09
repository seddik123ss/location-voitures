<?php

namespace App\Integration\Http;

use App\Exceptions\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Token client credentials (client Keycloak reservation-service), gardé en cache jusqu'à 30 s avant son expiration.
 * Keycloak injoignable ou client refusé -> 503 : le service appelé ne peut pas être joint.
 */
final class KeycloakServiceTokenProvider implements ServiceTokenProvider
{
    private const MARGIN_SECONDS = 30;

    public function token(): string
    {
        $cached = Cache::get($this->cacheKey());
        if (is_string($cached)) {
            return $cached;
        }
        [$token, $expiresIn] = $this->fetch();
        Cache::put($this->cacheKey(), $token, max(1, $expiresIn - self::MARGIN_SECONDS));

        return $token;
    }

    public function invalidate(): void
    {
        Cache::forget($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return 'keycloak_service_token_'.md5(config('keycloak.issuer').'|'.config('keycloak.client_id'));
    }

    /** @return array{string, int} */
    private function fetch(): array
    {
        $clientId = (string) config('keycloak.client_id');
        $secret = (string) config('keycloak.client_secret');
        if ($secret === '') {
            Log::error('KEYCLOAK_CLIENT_SECRET est vide : renseigner dans .env le secret du client Keycloak reservation-service (voir README)');
            throw ApiException::serviceUnavailable('Token de service impossible à obtenir : secret du client Keycloak non configuré');
        }
        $url = config('keycloak.token_url') ?? rtrim((string) config('keycloak.issuer'), '/').'/protocol/openid-connect/token';

        try {
            $response = Http::asForm()->acceptJson()
                ->connectTimeout((int) config('reservation.http_connect_timeout'))
                ->timeout((int) config('reservation.http_timeout'))
                ->post($url, ['grant_type' => 'client_credentials', 'client_id' => $clientId, 'client_secret' => $secret]);
        } catch (ConnectionException $e) {
            Log::error("Keycloak injoignable ($url) : ".$e->getMessage());
            throw ApiException::serviceUnavailable('Keycloak injoignable : token de service impossible à obtenir', $e);
        }

        if ($response->status() !== 200) {
            Log::error("Keycloak a refusé le client $clientId (HTTP {$response->status()})");
            throw ApiException::serviceUnavailable("Token de service refusé par Keycloak (HTTP {$response->status()})");
        }
        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in');
        if (! is_string($token) || ! is_int($expiresIn)) {
            throw ApiException::serviceUnavailable('Réponse de Keycloak sans access_token');
        }

        return [$token, $expiresIn];
    }
}
