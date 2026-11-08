<?php

namespace App\Tests\Functional;

use App\DataFixtures\DemoIds;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base des tests HTTP. Les données sont celles des fixtures (composer test:db) ;
 * DAMA annule la transaction à la fin de chaque test, donc chaque test part du même état.
 */
abstract class ApiTestCase extends WebTestCase
{
    /** Appelants de docs/demo-ids.md : [id, rôles]. */
    protected const CLIENT1 = [DemoIds::CLIENT1, 'CLIENT'];
    protected const CLIENT2 = [DemoIds::CLIENT2, 'CLIENT'];
    protected const AGENT1 = [DemoIds::AGENT1, 'AGENT'];
    protected const ADMIN1 = [DemoIds::ADMIN1, 'ADMIN'];
    protected const UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * @param array{0: string, 1: string}|null $as   appelant (null = sans authentification)
     * @param array<mixed>|string|null          $body tableau encodé en JSON, ou corps brut
     */
    protected function request(string $method, string $uri, ?array $as = null, array|string|null $body = null, string $contentType = 'application/json'): void
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($as !== null) {
            $server['HTTP_X_DEV_USER_ID'] = $as[0];
            $server['HTTP_X_DEV_ROLES'] = $as[1];
        }
        if ($body !== null) {
            $server['CONTENT_TYPE'] = $contentType;
        }
        $content = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body;

        $this->client->request($method, $uri, server: $server, content: $content);
    }

    /** @return array<string, mixed> */
    protected function json(): array
    {
        $response = $this->client->getResponse();
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'), 'Réponse non JSON');

        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> le corps décodé, après vérification du code */
    protected function assertJsonStatus(int $status): array
    {
        self::assertResponseStatusCodeSame($status, (string) $this->client->getResponse()->getContent());

        return $this->json();
    }

    /**
     * Vérifie le format d'erreur commun {status, error, message, details?}.
     *
     * @param list<string>|null $fields champs attendus dans details (null = ne pas vérifier)
     *
     * @return array<string, mixed>
     */
    protected function assertError(int $status, string $error, ?string $messageContains = null, ?array $fields = null): array
    {
        $body = $this->assertJsonStatus($status);
        self::assertSame($status, $body['status']);
        self::assertSame($error, $body['error']);
        self::assertIsString($body['message']);
        self::assertNotSame('', $body['message']);
        if ($messageContains !== null) {
            self::assertStringContainsString($messageContains, $body['message']);
        }
        if ($fields !== null) {
            self::assertSame($fields, array_column($body['details'] ?? [], 'field'));
        }

        return $body;
    }
}
