<?php

namespace App\Tests\Unit\Integration;

use App\Exception\ApiException;
use App\Integration\Http\ServiceResolver;

/** Résolveur Eureka factice : une adresse fixe, ou (null) aucune instance UP. */
final class FakeResolver implements ServiceResolver
{
    /** @var list<string> */
    public array $resolved = [];

    public function __construct(private readonly ?string $baseUrl = 'http://reservation.test:8103')
    {
    }

    public function baseUrl(string $app): string
    {
        $this->resolved[] = $app;

        return $this->baseUrl ?? throw ApiException::serviceUnavailable("Service $app indisponible : Aucune instance UP pour $app");
    }
}
