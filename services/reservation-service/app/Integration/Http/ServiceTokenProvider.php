<?php

namespace App\Integration\Http;

/** Token du compte de service reservation-service (rôle INTERNAL), pour appeler les routes /internal des autres. */
interface ServiceTokenProvider
{
    public function token(): string;

    /** Oublie le token en cache (refusé par le service appelé : expiré ou révoqué). */
    public function invalidate(): void;
}
