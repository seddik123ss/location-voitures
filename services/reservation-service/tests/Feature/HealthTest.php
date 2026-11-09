<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_repond_up(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertExactJson(['status' => 'UP'])
            ->assertHeader('X-Correlation-Id');
    }

    public function test_le_correlation_id_recu_est_renvoye(): void
    {
        $this->getJson('/health', ['X-Correlation-Id' => 'abc-123'])
            ->assertHeader('X-Correlation-Id', 'abc-123');
    }

    public function test_route_inconnue_au_format_d_erreur_commun(): void
    {
        $this->getJson('/nope')
            ->assertNotFound()
            ->assertExactJson(['status' => 404, 'error' => 'NOT_FOUND', 'message' => 'Ressource introuvable']);
    }

    public function test_methode_non_autorisee(): void
    {
        $this->postJson('/health')
            ->assertStatus(405)
            ->assertJsonPath('error', 'METHOD_NOT_ALLOWED');
    }
}
