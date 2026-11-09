<?php

namespace App\Tests\Unit\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class InternalApiClientTest extends IntegrationTestCase
{
    private const PATH = '/internal/reservations/r1';

    public function testSendsServiceTokenAndCorrelationIdToResolvedAddress(): void
    {
        $response = new MockResponse('{"id":"r1"}', ['http_code' => 200]);
        $resolver = new FakeResolver();

        $data = self::internalClient(new MockHttpClient($response), $resolver)->getJson('RENTAL-RESERVATION', self::PATH);

        self::assertSame(['id' => 'r1'], $data);
        self::assertSame(['RENTAL-RESERVATION'], $resolver->resolved);
        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame('http://reservation.test:8103/internal/reservations/r1', $response->getRequestUrl());
        $headers = $response->getRequestOptions()['normalized_headers'];
        self::assertSame(['Authorization: Bearer t1'], $headers['authorization']);
        self::assertSame(['X-Correlation-Id: ' . self::CORRELATION_ID], $headers['x-correlation-id']);
        self::assertSame(['Accept: application/json'], $headers['accept']);
    }

    public function testRemote404IsNull(): void
    {
        $http = new MockHttpClient(new MockResponse('{"status":404}', ['http_code' => 404]));

        self::assertNull(self::internalClient($http)->getJson('RENTAL-RESERVATION', self::PATH));
    }

    public function testNoInstanceInEurekaIs503WithoutHttpCall(): void
    {
        $http = new MockHttpClient(fn () => self::fail('Aucun appel HTTP attendu'));

        self::assertApiException(503, 'Aucune instance UP pour RENTAL-RESERVATION', fn () => self::internalClient($http, new FakeResolver(null))->getJson('RENTAL-RESERVATION', self::PATH));
    }

    public function testConnectionFailureIs503(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));

        self::assertApiException(503, 'Service RENTAL-RESERVATION injoignable', fn () => self::internalClient($http)->getJson('RENTAL-RESERVATION', self::PATH));
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function remoteErrors(): iterable
    {
        yield '502 distant -> 503' => [502, 503, 'indisponible (HTTP 502)'];
        yield '503 distant -> 503' => [503, 503, 'indisponible (HTTP 503)'];
        yield '504 distant -> 503' => [504, 503, 'indisponible (HTTP 504)'];
        yield '500 distant -> 502' => [500, 502, 'Réponse inattendue du service RENTAL-RESERVATION (HTTP 500)'];
        yield '400 distant -> 502' => [400, 502, 'Réponse inattendue du service RENTAL-RESERVATION (HTTP 400)'];
        yield '409 distant -> 502' => [409, 502, 'Réponse inattendue du service RENTAL-RESERVATION (HTTP 409)'];
    }

    #[DataProvider('remoteErrors')]
    public function testRemoteErrorStatuses(int $remote, int $expected, string $message): void
    {
        $http = new MockHttpClient(new MockResponse('{}', ['http_code' => $remote]));

        self::assertApiException($expected, $message, fn () => self::internalClient($http)->getJson('RENTAL-RESERVATION', self::PATH));
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableBodies(): iterable
    {
        yield 'HTML' => ['<html>Erreur</html>'];
        yield 'vide' => [''];
        yield 'liste JSON' => ['[1,2]'];
        yield 'chaîne JSON' => ['"ok"'];
    }

    #[DataProvider('unreadableBodies')]
    public function testUnreadableBodyIs502(string $body): void
    {
        $http = new MockHttpClient(new MockResponse($body, ['http_code' => 200]));

        self::assertApiException(502, 'Réponse illisible du service RENTAL-RESERVATION', fn () => self::internalClient($http)->getJson('RENTAL-RESERVATION', self::PATH));
    }

    public function testRejectedTokenIsRetriedOnceWithFreshToken(): void
    {
        $first = new MockResponse('', ['http_code' => 401]);
        $second = new MockResponse('{"id":"r1"}', ['http_code' => 200]);
        $tokens = new FakeTokens();

        $data = self::internalClient(new MockHttpClient([$first, $second]), tokens: $tokens)->getJson('RENTAL-RESERVATION', self::PATH);

        self::assertSame(['id' => 'r1'], $data);
        self::assertSame(1, $tokens->invalidations);
        self::assertSame(['Authorization: Bearer t1'], $first->getRequestOptions()['normalized_headers']['authorization']);
        self::assertSame(['Authorization: Bearer t2'], $second->getRequestOptions()['normalized_headers']['authorization']);
    }

    public function testTokenStillRejectedIs502(): void
    {
        $http = new MockHttpClient([new MockResponse('', ['http_code' => 401]), new MockResponse('', ['http_code' => 403])]);

        self::assertApiException(502, 'a refusé le jeton de service (HTTP 403)', fn () => self::internalClient($http)->getJson('RENTAL-RESERVATION', self::PATH));
    }
}
