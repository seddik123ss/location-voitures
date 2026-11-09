<?php

namespace App\Integration\Demo;

use App\DataFixtures\DemoIds;
use App\Integration\ContractInfo;
use App\Integration\ContractLookup;

/** Simulation de RENTAL-CONTRACT avec les contrats de docs/demo-ids.md. */
final class DemoContractLookup implements ContractLookup
{
    private const CONTRACTS = [
        DemoIds::C_COMPLETED => [DemoIds::R_COMPLETED, DemoIds::CLIENT2, 'CLOSED'],
    ];

    public function find(string $contractId): ?ContractInfo
    {
        $row = self::CONTRACTS[strtolower($contractId)] ?? null;

        return $row === null ? null : new ContractInfo(strtolower($contractId), $row[0], $row[1], $row[2]);
    }
}
