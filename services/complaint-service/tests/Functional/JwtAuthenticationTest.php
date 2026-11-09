<?php

namespace App\Tests\Functional;

use App\DataFixtures\DemoIds;
use App\Tests\Support\TestJwt;
use Firebase\JWT\JWT;

/** Authentification par access token Keycloak, avec de vrais JWT signés par la clé de test (tests/Fixtures/jwt). */
final class JwtAuthenticationTest extends ApiTestCase
{
    private function withToken(string $method, string $uri, string $token, ?array $body = null): void
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $this->client->request($method, $uri, server: $server, content: $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null);
    }

    private function assertUnauthorized(string $message): void
    {
        $this->assertError(401, 'UNAUTHORIZED', $message);
        self::assertSame('Bearer error="invalid_token"', $this->client->getResponse()->headers->get('WWW-Authenticate'));
    }

    public function testValidClientTokenSeesOnlyOwnComplaints(): void
    {
        $this->withToken('GET', '/api/complaints', TestJwt::token(DemoIds::CLIENT2, ['CLIENT']));

        // Fixtures : 3 réclamations de client2, 2 de client1.
        $page = $this->assertJsonStatus(200);
        self::assertSame(3, $page['total']);
        self::assertSame([DemoIds::CLIENT2], array_values(array_unique(array_column($page['items'], 'userId'))));
    }

    public function testAgentTokenSeesEveryComplaint(): void
    {
        $this->withToken('GET', '/api/complaints?size=100', TestJwt::token(DemoIds::AGENT1, ['AGENT']));

        $page = $this->assertJsonStatus(200);
        self::assertSame(5, $page['total']);
        $owners = array_unique(array_column($page['items'], 'userId'));
        sort($owners);
        self::assertSame([DemoIds::CLIENT2, DemoIds::CLIENT1], $owners);
    }

    public function testCallerIdComesFromSub(): void
    {
        $body = ['reservationId' => DemoIds::R_CONFIRMED, 'category' => 'VEHICLE', 'subject' => 'Rayure', 'description' => 'Rayure sur la portière.'];
        $this->withToken('POST', '/api/complaints', TestJwt::token(strtoupper(DemoIds::CLIENT1), ['CLIENT']), $body);

        self::assertSame(DemoIds::CLIENT1, $this->assertJsonStatus(201)['userId']);
    }

    public function testExpiredTokenIs401(): void
    {
        $this->withToken('GET', '/api/complaints', TestJwt::token(DemoIds::CLIENT2, ['CLIENT'], ['iat' => time() - 900, 'exp' => time() - 600]));

        $this->assertUnauthorized('Token expiré');
    }

    public function testTokenWithoutExpirationIs401(): void
    {
        $this->withToken('GET', '/api/complaints', TestJwt::token(DemoIds::CLIENT2, ['CLIENT'], ['exp' => null]));

        $this->assertUnauthorized("Token sans date d'expiration");
    }

    public function testWrongIssuerIs401(): void
    {
        $this->withToken('GET', '/api/complaints', TestJwt::token(DemoIds::CLIENT2, ['CLIENT'], ['iss' => 'http://localhost:8180/realms/master']));

        $this->assertUnauthorized('Émetteur du token invalide');
    }

    public function testWrongSignatureIs401(): void
    {
        // Même kid que la clé de test, mais signé par une autre clé.
        $this->withToken('GET', '/api/complaints', TestJwt::token(DemoIds::CLIENT2, ['CLIENT'], keyFile: TestJwt::OTHER_KEY_FILE));

        $this->assertUnauthorized('Signature du token invalide');
    }

    public function testUnknownKeyIs401(): void
    {
        $this->withToken('GET', '/api/complaints', TestJwt::token(DemoIds::CLIENT2, ['CLIENT'], keyFile: TestJwt::OTHER_KEY_FILE, kid: 'other-key'));

        $this->assertUnauthorized('Token signé par une clé inconnue');
    }

    public function testHs256TokenIsRefused(): void
    {
        // Confusion d'algorithme : HS256 avec la clé publique comme secret.
        $token = JWT::encode(['iss' => TestJwt::ISSUER, 'sub' => DemoIds::CLIENT2, 'exp' => time() + 300, 'roles' => ['CLIENT']], json_encode(TestJwt::publicJwk()), 'HS256', TestJwt::KID);
        $this->withToken('GET', '/api/complaints', $token);

        $this->assertUnauthorized('signature RS256 avec kid attendue');
    }

    public function testMalformedTokenIs401(): void
    {
        $this->withToken('GET', '/api/complaints', 'pas-un-jwt');

        $this->assertUnauthorized('Token invalide');
    }

    public function testNonBearerSchemeIs401(): void
    {
        $this->client->request('GET', '/api/complaints', server: ['HTTP_AUTHORIZATION' => 'Basic Y2xpZW50MTpjbGllbnQxX3B3ZA==']);

        $this->assertUnauthorized('Bearer <token>');
    }

    public function testTokenWithoutRoleIs403(): void
    {
        // Seulement les rôles par défaut de Keycloak.
        $this->withToken('GET', '/api/complaints', TestJwt::token(DemoIds::CLIENT2, []));

        $this->assertError(403, 'FORBIDDEN', 'Accès refusé : rôle CLIENT, AGENT ou ADMIN requis');
    }

    public function testServiceAccountTokenIs403OnPublicRoutes(): void
    {
        $this->withToken('GET', '/api/complaints', TestJwt::token(self::UNKNOWN_ID, ['INTERNAL']));

        $this->assertError(403, 'FORBIDDEN');
    }

    public function testPublicRoutesIgnoreInvalidToken(): void
    {
        foreach (['/health', '/api/doc.json'] as $uri) {
            $this->withToken('GET', $uri, 'pas-un-jwt');
            self::assertResponseStatusCodeSame(200, $uri);
        }
    }
}
