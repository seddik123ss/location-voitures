<?php

namespace App\Integration;

use App\Exceptions\ApiException;

/** Accès à user-service (RENTAL-USER). */
interface UserLookup
{
    /**
     * @return UserInfo|null null si l'utilisateur n'existe pas
     *
     * @throws ApiException 503 (service indisponible) ou 502 (réponse illisible)
     */
    public function find(string $userId): ?UserInfo;
}
