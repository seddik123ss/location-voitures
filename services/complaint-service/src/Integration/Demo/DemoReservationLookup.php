<?php

namespace App\Integration\Demo;

use App\DataFixtures\DemoIds;
use App\Integration\ReservationInfo;
use App\Integration\ReservationLookup;

/** Simulation de RENTAL-RESERVATION avec les réservations de docs/demo-ids.md. */
final class DemoReservationLookup implements ReservationLookup
{
    private const RESERVATIONS = [
        DemoIds::R_PENDING => [DemoIds::CLIENT1, 'PENDING'],
        DemoIds::R_CONFIRMED => [DemoIds::CLIENT1, 'CONFIRMED'],
        DemoIds::R_COMPLETED => [DemoIds::CLIENT2, 'COMPLETED'],
    ];

    public function find(string $reservationId): ?ReservationInfo
    {
        $row = self::RESERVATIONS[strtolower($reservationId)] ?? null;

        return $row === null ? null : new ReservationInfo(strtolower($reservationId), $row[0], $row[1]);
    }
}
