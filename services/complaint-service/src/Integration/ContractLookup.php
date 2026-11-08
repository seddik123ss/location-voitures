<?php

namespace App\Integration;

use App\Exception\ApiException;

/** Accès à RENTAL-CONTRACT (GET /internal/contracts/{id}). */
interface ContractLookup
{
    /**
     * @return ContractInfo|null null si le contrat n'existe pas (404 du service distant)
     *
     * @throws ApiException 503 si le service ou Eureka est indisponible, 502 si la réponse est illisible
     */
    public function find(string $contractId): ?ContractInfo;
}
