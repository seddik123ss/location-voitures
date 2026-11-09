<?php

namespace Tests;

use App\Security\KeySetProvider;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use App\Events\ReservationEventPublisher;
use Tests\Support\RecordingEventPublisher;
use Tests\Support\TestKeySetProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Aucune requête vers Keycloak pendant les tests : les tokens sont signés par la clé de test.
        $this->app->singleton(KeySetProvider::class, TestKeySetProvider::class);
        // Aucun message RabbitMQ : les messages publiés sont gardés dans $this->events.
        $this->events = new RecordingEventPublisher();
        $this->app->instance(ReservationEventPublisher::class, $this->events);
    }

    protected RecordingEventPublisher $events;

    /** GET avec un en-tête Authorization: Bearer (aucun en-tête si $token est null). */
    protected function getWithToken(string $uri, ?string $token): TestResponse
    {
        return $this->getJson($uri, $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
    }

    /** Requête JSON avec un token (aucun en-tête Authorization si $token est null). */
    protected function api(string $method, string $uri, ?string $token, array $body = []): TestResponse
    {
        return $this->json($method, $uri, $body, $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
    }

    /** Vérifie une erreur au format commun {status, error, message, details?}. */
    protected function assertError(TestResponse $response, int $status, string $error, ?string $messagePart = null, array $fields = []): void
    {
        $response->assertStatus($status)
            ->assertJsonPath('status', $status)
            ->assertJsonPath('error', $error)
            ->assertHeader('X-Correlation-Id');
        $this->assertIsString($response->json('message'));
        if ($messagePart !== null) {
            $this->assertStringContainsString($messagePart, $response->json('message'));
        }
        if ($fields !== []) {
            $this->assertEqualsCanonicalizing($fields, array_values(array_unique(array_column($response->json('details') ?? [], 'field'))));
        }
    }
}
