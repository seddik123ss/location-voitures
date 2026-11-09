<?php

namespace App\Tests\Unit\Security;

use App\Exception\ApiException;
use App\Security\Jwt\KeycloakJwksProvider;
use App\Tests\Support\TestJwt;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class KeycloakJwksProviderTest extends TestCase
{
    private const CERTS_URL = TestJwt::ISSUER . '/protocol/openid-connect/certs';

    private static function jwks(string ...$kids): MockResponse
    {
        $keys = [['kid' => 'enc-key', 'kty' => 'RSA', 'alg' => 'RSA-OAEP', 'use' => 'enc', 'n' => 'AQAB', 'e' => 'AQAB']];
        foreach ($kids as $kid) {
            $keys[] = TestJwt::publicJwk(kid: $kid);
        }

        return new MockResponse(json_encode(['keys' => $keys]), ['response_headers' => ['Content-Type' => 'application/json']]);
    }

    private static function provider(MockHttpClient $http, ?ArrayAdapter $cache = null): KeycloakJwksProvider
    {
        return new KeycloakJwksProvider($http, $cache ?? new ArrayAdapter(), new NullLogger(), TestJwt::ISSUER);
    }

    public function testKeepsOnlySignatureKeysAndCachesThem(): void
    {
        $urls = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = "$method $url";

            return self::jwks('k1');
        });
        $provider = self::provider($http);

        self::assertSame(['k1'], array_keys($provider->keys()));
        self::assertSame(['k1'], array_keys($provider->keys()));
        self::assertSame(['GET ' . self::CERTS_URL], $urls);
    }

    public function testRefreshReloadsKeysAtMostOncePerMinute(): void
    {
        $http = new MockHttpClient([self::jwks('k1'), self::jwks('k1', 'k2')]);
        $provider = self::provider($http);
        $provider->keys();

        self::assertSame(['k1', 'k2'], array_keys($provider->keys(refresh: true)));
        $provider->keys(refresh: true); // trop tôt : pas de nouvel appel
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testKeycloakUnreachableIs503(): void
    {
        $provider = self::provider(new MockHttpClient(static fn () => throw new TransportException('Connection refused')));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Keycloak injoignable');
        $provider->keys();
    }

    public function testFailureIsNotCached(): void
    {
        $http = new MockHttpClient([new MockResponse('', ['http_code' => 500]), self::jwks('k1')]);
        $provider = self::provider($http);

        try {
            $provider->keys();
            self::fail('503 attendu');
        } catch (ApiException $e) {
            self::assertSame(503, $e->status);
        }
        self::assertSame(['k1'], array_keys($provider->keys()));
    }
}
