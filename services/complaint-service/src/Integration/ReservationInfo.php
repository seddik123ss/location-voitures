<?php

namespace App\Integration;

/** Sous-ensemble de GET /internal/reservations/{id} utile au service (docs/internal-api.md). */
final readonly class ReservationInfo
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $status,
    ) {
    }
}
