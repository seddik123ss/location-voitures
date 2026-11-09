<?php

namespace App\Enums;

/** Statuts de réservation communs (docs/internal-api.md). */
enum ReservationStatus: string
{
    case PENDING = 'PENDING';
    case CONFIRMED = 'CONFIRMED';
    case ONGOING = 'ONGOING';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    /**
     * Statuts qui bloquent le véhicule (décision d'équipe) : une réservation PENDING, CONFIRMED ou ONGOING
     * compte comme active dans GET /internal/reservations/active et dans le contrôle de chevauchement.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::PENDING, self::CONFIRMED, self::ONGOING];
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::active());
    }
}
