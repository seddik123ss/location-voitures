<?php

namespace App\Integration;

/** Sous-ensemble de GET /internal/contracts/{id} utile au service (docs/internal-api.md). */
final readonly class ContractInfo
{
    public function __construct(
        public string $id,
        public string $reservationId,
        public string $userId,
        public string $status,
    ) {
    }
}
