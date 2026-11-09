<?php

namespace App\Security\Jwt;

use Firebase\JWT\Key;

/** Clés publiques de signature du realm, indexées par kid. */
interface KeySetProvider
{
    /**
     * @param bool $refresh true pour recharger les clés (kid inconnu : Keycloak a peut-être changé de clé)
     *
     * @return array<string, Key>
     *
     * @throws \App\Exception\ApiException 503 si les clés sont impossibles à obtenir
     */
    public function keys(bool $refresh = false): array;
}
