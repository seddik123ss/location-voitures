<?php

namespace App\Tests\Unit\Integration;

use App\Integration\Http\KeycloakServiceTokenProvider;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class KeycloakServiceTokenProviderTest extends IntegrationTestCase
{
    private const ISSUER = 'http://keycloak.test/realms/location-voitures';

    public function testRequestsClientCredentialsAndCachesToken(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = [$method, $url, $options['body']];

            return new MockResponse(json_encode(['access_token' => 'jwt-' . count($requests), 'expires_in' => 300]), ['http_code' => 200]);
        });
        $provider = $this->provider($http);

        self::assertSame('jwt-1', $provider->token());
        self::assertSame('jwt-1', $provider->token(), 'Deuxième appel servi par le cache');
        self::assertCount(1, $requests);
        [$method, $url, $body] = $requests[0];
        self::assertSame('POST', $method);
        self::assertSame(self::ISSUER . '/protocol/openid-connect/token', $url);
        parse_str($body, $form);
        self::assertSame(['grant_type' => 'client_credentials', 'client_id' => 'complaint-service', 'client_secret' => 's3cret'], $form);

        $provider->invalidate();
        self::assertSame('jwt-2', $provider->token(), 'Nouveau jeton après invalidate()');
    }

    public function testKeycloakUnreachableIs503(): void
    {
        $provider = $this->provider(new MockHttpClient(new MockResponse('', ['error' => 'Connection refused'])));

        self::assertApiException(503, 'Keycloak injoignable', fn () => $provider->token());
    }

    public function testRejectedClientIs503(): void
    {
        $provider = $this->provider(new MockHttpClient(new MockResponse('{"error":"unauthorized_client"}', ['http_code' => 401])));

        self::assertApiException(503, 'Jeton de service refusé par Keycloak (HTTP 401)', fn () => $provider->token());
    }

    public function testFailureIsNotCached(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['error' => 'Connection refused']),
            new MockResponse('{"access_token":"jwt-ok","expires_in":300}', ['http_code' => 200]),
        ]);
        $provider = $this->provider($http);

        self::assertApiException(503, 'Keycloak injoignable', fn () => $provider->token());
        self::assertSame('jwt-ok', $provider->token());
    }

    public function testMissingSecretIsAConfigurationError(): void
    {
        $provider = new KeycloakServiceTokenProvider(new MockHttpClient(), new ArrayAdapter(), new NullLogger(), self::ISSUER, 'complaint-service', '');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('KEYCLOAK_CLIENT_SECRET');
        $provider->token();
    }

    private function provider(MockHttpClient $http): KeycloakServiceTokenProvider
    {
        return new KeycloakServiceTokenProvider($http, new ArrayAdapter(), new NullLogger(), self::ISSUER, 'complaint-service', 's3cret');
    }
}
