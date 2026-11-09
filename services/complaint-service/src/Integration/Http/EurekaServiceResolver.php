<?php

namespace App\Integration\Http;

use App\Exception\ApiException;
use Rental\Eureka\EurekaClient;
use Rental\Eureka\EurekaUnavailable;

/** Résolution par le client Eureka partagé (shared/php-eureka). */
final class EurekaServiceResolver implements ServiceResolver
{
    public function __construct(private readonly EurekaClient $eureka)
    {
    }

    public function baseUrl(string $app): string
    {
        try {
            return $this->eureka->resolve($app);
        } catch (EurekaUnavailable $e) {
            throw ApiException::serviceUnavailable(sprintf('Service %s indisponible : %s', $app, $e->getMessage()), $e);
        }
    }
}
