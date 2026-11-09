<?php

namespace Tests\Feature;

use App\Eureka\EurekaRegistry;
use App\Eureka\ServiceRegistry;
use Illuminate\Support\Sleep;
use Tests\Support\FakeServiceRegistry;
use Tests\TestCase;

class EurekaCommandTest extends TestCase
{
    private FakeServiceRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->registry = new FakeServiceRegistry();
        $this->app->instance(ServiceRegistry::class, $this->registry);
    }

    public function test_instance_annoncee_sous_host_docker_internal_port_8103(): void
    {
        config(['eureka.host' => 'host.docker.internal', 'eureka.port' => 8103]);

        $this->assertSame('host.docker.internal:rental-reservation:8103', EurekaRegistry::fromConfig()->instanceId());
    }

    public function test_enregistrement_battements_puis_retrait(): void
    {
        $this->artisan('eureka:run', ['--interval' => 30, '--beats' => 3])
            ->expectsOutputToContain('Enregistré dans Eureka : host.docker.internal:rental-reservation:8103')
            ->expectsOutputToContain("Retiré d'Eureka")
            ->assertSuccessful();

        $this->assertSame(['register', 'heartbeat', 'heartbeat', 'heartbeat', 'deregister'], $this->registry->calls);
        // Un battement toutes les 30 s, par pauses d'une seconde (réaction rapide à Ctrl+C).
        Sleep::assertSleptTimes(90);
    }

    public function test_intervalle_de_30_secondes_par_defaut(): void
    {
        $this->artisan('eureka:run', ['--beats' => 1])->assertSuccessful();

        Sleep::assertSleptTimes(30);
    }

    public function test_eureka_absent_au_demarrage_nouvel_essai_au_battement_suivant(): void
    {
        $this->registry->failures['register'] = 1;

        $this->artisan('eureka:run', ['--interval' => 1, '--beats' => 2])
            ->expectsOutputToContain('Eureka indisponible, nouvel essai au prochain battement')
            ->assertSuccessful();

        $this->assertSame(['register', 'register', 'heartbeat', 'deregister'], $this->registry->calls);
    }

    public function test_battement_refuse_reenregistrement(): void
    {
        // Eureka a redémarré ou oublié l'instance : le battement échoue, la commande se réenregistre.
        $this->registry->failures['heartbeat'] = 1;

        $this->artisan('eureka:run', ['--interval' => 1, '--beats' => 2])
            ->expectsOutputToContain('Battement refusé')
            ->assertSuccessful();

        $this->assertSame(['register', 'heartbeat', 'register', 'heartbeat', 'deregister'], $this->registry->calls);
    }

    public function test_jamais_enregistre_pas_de_retrait(): void
    {
        $this->registry->failures['register'] = 99;

        $this->artisan('eureka:run', ['--interval' => 1, '--beats' => 2])->assertSuccessful();

        $this->assertNotContains('deregister', $this->registry->calls);
    }

    public function test_retrait_impossible_signale_sans_erreur(): void
    {
        $this->registry->failures['deregister'] = 1;

        $this->artisan('eureka:run', ['--interval' => 1, '--beats' => 1])
            ->expectsOutputToContain("Eureka retirera l'instance à l'expiration du bail")
            ->assertSuccessful();
    }

    public function test_arret_demande_retire_l_instance(): void
    {
        // Simule Ctrl+C pendant la 5e seconde d'attente.
        $sleeps = 0;
        Sleep::whenFakingSleep(function () use (&$sleeps) {
            if (++$sleeps === 5) {
                $command = collect($this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all())->get('eureka:run');
                $command->requestStop();
            }
        });

        $this->artisan('eureka:run', ['--interval' => 30])->assertSuccessful();

        $this->assertSame(['register', 'deregister'], $this->registry->calls);
        Sleep::assertSleptTimes(5);
    }

    public function test_commande_de_retrait(): void
    {
        $this->artisan('eureka:deregister')
            ->expectsOutputToContain("Retiré d'Eureka : host.docker.internal:rental-reservation:8103")
            ->assertSuccessful();

        $this->registry->failures['deregister'] = 1;
        $this->artisan('eureka:deregister')->expectsOutputToContain('Retrait impossible')->assertFailed();
    }
}
