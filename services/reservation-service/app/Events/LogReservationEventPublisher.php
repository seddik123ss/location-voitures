<?php

namespace App\Events;

use App\Models\Reservation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/** En attendant RabbitMQ (étape 5) : les messages sont seulement écrits dans le log. */
final class LogReservationEventPublisher implements ReservationEventPublisher
{
    public function reservationCancelled(Reservation $reservation, CarbonInterface $cancelledAt, ?string $reason): void
    {
        Log::info("[SIMULATION] ReservationCancelled {$reservation->id} (RabbitMQ pas encore branché)");
    }

    public function reservationCompleted(Reservation $reservation): void
    {
        Log::info("[SIMULATION] ReservationCompleted {$reservation->id} (RabbitMQ pas encore branché)");
    }
}
