<?php

namespace App\Tests\Functional;

use App\DataFixtures\DemoIds;
use PHPUnit\Framework\Attributes\DataProvider;

/** Contrôles communs à toutes les routes protégées : 401 sans authentification, 415 / 400 sur les corps. */
final class EveryRouteTest extends ApiTestCase
{
    /** @return iterable<string, array{string, string, array<mixed>|null}> */
    public static function protectedRoutes(): iterable
    {
        $id = DemoIds::REC_1;
        yield 'GET liste' => ['GET', '/api/complaints', null];
        yield 'GET détail' => ['GET', "/api/complaints/$id", null];
        yield 'POST création' => ['POST', '/api/complaints', ['reservationId' => DemoIds::R_CONFIRMED, 'category' => 'BILLING', 'subject' => 's', 'description' => 'd']];
        yield 'PUT modification' => ['PUT', "/api/complaints/$id", ['subject' => 's', 'description' => 'd', 'category' => 'BILLING']];
        yield 'DELETE suppression' => ['DELETE', "/api/complaints/$id", null];
        yield 'PATCH statut' => ['PATCH', "/api/complaints/$id/status", ['status' => 'IN_PROGRESS']];
        yield 'PATCH priorité' => ['PATCH', "/api/complaints/$id/priority", ['priority' => 'HIGH']];
        yield 'POST message' => ['POST', "/api/complaints/$id/messages", ['content' => 'Bonjour']];
    }

    /** @param array<mixed>|null $body */
    #[DataProvider('protectedRoutes')]
    public function testRequiresAuthentication(string $method, string $uri, ?array $body): void
    {
        $this->request($method, $uri, null, $body);

        $this->assertError(401, 'UNAUTHORIZED', 'Authentification requise');
    }

    /** @return iterable<string, array{string, string, array<mixed>}> */
    public static function routesWithBody(): iterable
    {
        foreach (self::protectedRoutes() as $name => [$method, $uri, $body]) {
            if ($body !== null) {
                yield $name => [$method, $uri, $body];
            }
        }
    }

    /** @param array<mixed> $body */
    #[DataProvider('routesWithBody')]
    public function testRejectsNonJsonContentType(string $method, string $uri, array $body): void
    {
        $this->request($method, $uri, self::ADMIN1, json_encode($body), 'text/plain');

        $this->assertError(415, 'UNSUPPORTED_MEDIA_TYPE', 'application/json');
    }

    /** @param array<mixed> $body */
    #[DataProvider('routesWithBody')]
    public function testRejectsMalformedJson(string $method, string $uri, array $body): void
    {
        $this->request($method, $uri, self::ADMIN1, '{"oups": ');

        $this->assertError(400, 'BAD_REQUEST', 'Corps JSON invalide');
    }

    /** Ordre documenté : le corps est validé avant la recherche de la réclamation (400 et non 404). */
    public function testInvalidBodyWinsOverUnknownComplaint(): void
    {
        $this->request('PATCH', '/api/complaints/' . self::UNKNOWN_ID . '/status', self::AGENT1, ['status' => 'CLOSED']);
        $this->assertError(400, 'BAD_REQUEST', fields: ['status']);

        $this->request('PATCH', '/api/complaints/' . self::UNKNOWN_ID . '/status', self::AGENT1, ['status' => 'IN_PROGRESS']);
        $this->assertError(404, 'NOT_FOUND');
    }
}
