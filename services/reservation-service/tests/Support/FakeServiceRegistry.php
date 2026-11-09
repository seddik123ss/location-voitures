<?php

namespace Tests\Support;

use App\Eureka\ServiceRegistry;
use Rental\Eureka\EurekaUnavailable;

/** Eureka simulé : garde la liste des appels ; chaque méthode peut échouer sur commande. */
final class FakeServiceRegistry implements ServiceRegistry
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, int> nombre d'échecs à produire par méthode */
    public array $failures = [];

    /** @var array<string, string> adresse par nom Eureka */
    public array $instances = [];

    public function instanceId(): string
    {
        return 'host.docker.internal:rental-reservation:8103';
    }

    public function register(): void
    {
        $this->call('register');
    }

    public function heartbeat(): void
    {
        $this->call('heartbeat');
    }

    public function deregister(): void
    {
        $this->call('deregister');
    }

    public function resolve(string $app): string
    {
        $this->call("resolve:$app");
        if (! isset($this->instances[$app])) {
            throw new EurekaUnavailable("Aucune instance UP pour $app");
        }

        return $this->instances[$app];
    }

    private function call(string $name): void
    {
        $this->calls[] = $name;
        $key = explode(':', $name)[0];
        if (($this->failures[$key] ?? 0) > 0) {
            $this->failures[$key]--;
            throw new EurekaUnavailable("Échec simulé : $name");
        }
    }
}
