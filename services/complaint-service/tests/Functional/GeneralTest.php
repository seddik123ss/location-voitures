<?php

namespace App\Tests\Functional;

/** /health, documentation, authentification et erreurs génériques (routes inconnues, méthodes, Content-Type). */
final class GeneralTest extends ApiTestCase
{
    public function testHealthIsPublic(): void
    {
        $this->request('GET', '/health');

        self::assertSame(['status' => 'UP'], $this->assertJsonStatus(200));
    }

    public function testApiDocIsPublic(): void
    {
        $this->request('GET', '/api/doc.json');
        $doc = $this->assertJsonStatus(200);
        self::assertSame('complaint-service', $doc['info']['title']);
        self::assertArrayHasKey('bearerAuth', $doc['components']['securitySchemes']);
        self::assertArrayHasKey('/api/complaints/{id}/priority', $doc['paths']);

        $this->client->request('GET', '/api/doc');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('swagger-ui', (string) $this->client->getResponse()->getContent());
    }

    public function testUnknownRouteIs404InCommonFormat(): void
    {
        $this->request('GET', '/api/nope', self::CLIENT1);

        $this->assertError(404, 'NOT_FOUND', 'Route inconnue : GET /api/nope');
    }

    public function testNonUuidIdIsUnknownRoute(): void
    {
        $this->request('GET', '/api/complaints/pas-un-uuid', self::CLIENT1);

        $this->assertError(404, 'NOT_FOUND', 'Route inconnue');
    }

    public function testMissingAuthenticationIs401(): void
    {
        $this->request('GET', '/api/complaints');

        $this->assertError(401, 'UNAUTHORIZED', 'Authentification requise');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
    }

    public function testInvalidDevUserIdIs401(): void
    {
        $this->request('GET', '/api/complaints', ['pas-un-uuid', 'CLIENT']);

        $this->assertError(401, 'UNAUTHORIZED', 'X-Dev-User-Id doit être un UUID');
    }

    public function testUnknownDevRoleIs401(): void
    {
        $this->request('GET', '/api/complaints', [self::CLIENT1[0], 'SUPERUSER']);

        $this->assertError(401, 'UNAUTHORIZED', 'X-Dev-Roles invalide');
    }

    public function testMethodNotAllowedIs405(): void
    {
        $this->request('POST', '/api/complaints/' . self::UNKNOWN_ID, self::CLIENT1, []);

        $this->assertError(405, 'METHOD_NOT_ALLOWED', 'GET, PUT, DELETE');
    }

    public function testDetailsOnlyWhenPresent(): void
    {
        $this->request('GET', '/api/complaints/' . self::UNKNOWN_ID, self::AGENT1);

        self::assertArrayNotHasKey('details', $this->assertError(404, 'NOT_FOUND'));
    }
}
