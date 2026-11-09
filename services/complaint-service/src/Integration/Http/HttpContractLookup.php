<?php

namespace App\Integration\Http;

use App\Integration\ContractInfo;
use App\Integration\ContractLookup;

/** GET /internal/contracts/{id} sur RENTAL-CONTRACT (docs/internal-api.md). */
final class HttpContractLookup implements ContractLookup
{
    public const APP = 'RENTAL-CONTRACT';

    public function __construct(private readonly InternalApiClient $client)
    {
    }

    public function find(string $contractId): ?ContractInfo
    {
        $data = $this->client->getJson(self::APP, '/internal/contracts/' . rawurlencode(strtolower($contractId)));
        if ($data === null) {
            return null;
        }

        return new ContractInfo(
            Payload::uuid($data, 'id', self::APP),
            Payload::uuid($data, 'reservationId', self::APP),
            Payload::uuid($data, 'userId', self::APP),
            Payload::string($data, 'status', self::APP),
        );
    }
}
