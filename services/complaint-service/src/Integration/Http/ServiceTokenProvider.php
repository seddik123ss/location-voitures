<?php

namespace App\Integration\Http;

use App\Exception\ApiException;

/** Jeton du compte de service complaint-service (client credentials, rôle INTERNAL). */
interface ServiceTokenProvider
{
    /** @throws ApiException 503 si Keycloak est injoignable ou refuse le client */
    public function token(): string;

    /** Oublie le jeton en cache (par exemple après un 401 d'un autre service). */
    public function invalidate(): void;
}
