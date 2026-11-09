<?php

namespace App\Integration\Http;

use App\Exception\ApiException;

/** Trouve l'adresse d'un autre service (Eureka). */
interface ServiceResolver
{
    /**
     * @return string adresse de base, ex. http://host.docker.internal:8103
     *
     * @throws ApiException 503 si Eureka est injoignable ou si aucune instance n'est UP
     */
    public function baseUrl(string $app): string;
}
