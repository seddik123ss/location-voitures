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
     * Cycle de vie :
     *   PENDING ──► CONFIRMED ──► ONGOING ──► COMPLETED
     *      │            │
     *      └────────────┴──► CANCELLED
     * PENDING -> CONFIRMED : paiement RENTAL (message PaymentSucceeded) ou AGENT / ADMIN.
     * CONFIRMED -> ONGOING : AGENT / ADMIN à la remise du véhicule.
     * ONGOING -> COMPLETED : fin du contrat (message ContractClosed) ou AGENT / ADMIN.
     * COMPLETED et CANCELLED sont finaux.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::CONFIRMED, self::CANCELLED],
            self::CONFIRMED => [self::ONGOING, self::CANCELLED],
            self::ONGOING => [self::COMPLETED],
            self::COMPLETED, self::CANCELLED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Annulation possible seulement avant la remise du véhicule. */
    public function isCancellable(): bool
    {
        return $this->canTransitionTo(self::CANCELLED);
    }

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

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /** @param list<self> $statuses @return list<string> */
    public static function toValues(array $statuses): array
    {
        return array_map(static fn (self $s): string => $s->value, $statuses);
    }
}
