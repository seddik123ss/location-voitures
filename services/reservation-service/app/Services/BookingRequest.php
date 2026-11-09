<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/** Données validées d'une création ou d'une modification de réservation. */
final class BookingRequest
{
    public function __construct(
        public readonly string $userId,
        public readonly string $vehicleId,
        public readonly CarbonImmutable $startDate,
        public readonly CarbonImmutable $endDate,
        public readonly ?string $pickupAgencyId = null,
        public readonly ?string $returnAgencyId = null,
    ) {
    }

    /** Nombre de jours facturés : endDate - startDate (du 10 au 13 = 3 jours). */
    public function days(): int
    {
        return (int) $this->startDate->diffInDays($this->endDate);
    }
}
