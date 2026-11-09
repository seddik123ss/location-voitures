<?php

namespace App\Eureka;

use Rental\Eureka\EurekaClient;

/** Implémentation réelle : délègue au client partagé shared/php-eureka (API REST d'Eureka). */
final class EurekaRegistry implements ServiceRegistry
{
    public function __construct(private readonly EurekaClient $client)
    {
    }

    public static function fromConfig(): self
    {
        return new self(new EurekaClient(
            (string) config('eureka.url'),
            (string) config('eureka.app'),
            (int) config('eureka.port'),
            // Passé explicitement : la valeur du .env n'est pas visible par getenv(), que le client lit par défaut.
            (string) config('eureka.host'),
        ));
    }

    public function instanceId(): string
    {
        return $this->client->instanceId();
    }

    public function register(): void
    {
        $this->client->register();
    }

    public function heartbeat(): void
    {
        $this->client->heartbeat();
    }

    public function deregister(): void
    {
        $this->client->deregister();
    }

    public function resolve(string $app): string
    {
        return $this->client->resolve($app);
    }
}
