<?php

namespace Tests\Support;

use App\Events\ReservationEventPublisher;
use App\Exceptions\ApiException;
use App\Models\Reservation;
use Carbon\CarbonInterface;

/** Remplace RabbitMQ dans les tests : garde les messages ; peut simuler un broker indisponible. */
final class RecordingEventPublisher implements ReservationEventPublisher
{
    /** @var list<array{type: string, reservationId: string, reason?: string|null}> */
    public array $published = [];

    public bool $failCancelled = false;

    public function reservationCancelled(Reservation $reservation, CarbonInterface $cancelledAt, ?string $reason): void
    {
        if ($this->failCancelled) {
            throw ApiException::serviceUnavailable('Annulation impossible pour le moment : RabbitMQ injoignable : ReservationCancelled non publié');
        }
        $this->published[] = ['type' => 'ReservationCancelled', 'reservationId' => $reservation->id, 'reason' => $reason];
    }

    public function reservationCompleted(Reservation $reservation): void
    {
        $this->published[] = ['type' => 'ReservationCompleted', 'reservationId' => $reservation->id];
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_column($this->published, 'type');
    }
}
