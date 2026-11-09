<?php

namespace App\Events;

use App\Exceptions\ApiException;
use App\Http\Json;
use App\Messaging\AmqpPublisher;
use App\Messaging\Envelope;
use App\Messaging\PublishFailed;
use App\Models\Reservation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/** Messages publiés par reservation-service (docs/events/ReservationCancelled.json, ReservationCompleted.json). */
final class AmqpReservationEventPublisher implements ReservationEventPublisher
{
    public function __construct(private readonly AmqpPublisher $publisher)
    {
    }

    /**
     * Dans la transaction de l'annulation : sans confirmation de RabbitMQ, l'annulation est annulée (503),
     * pour que payment-service ne manque jamais un remboursement.
     */
    public function reservationCancelled(Reservation $reservation, CarbonInterface $cancelledAt, ?string $reason): void
    {
        $data = [
            'reservationId' => $reservation->id,
            'userId' => $reservation->user_id,
            'cancelledAt' => $cancelledAt->copy()->utc()->toIso8601ZuluString(),
        ];
        if ($reason !== null) {
            $data['reason'] = $reason;
        }

        try {
            $this->publisher->publish(config('rabbitmq.publish.reservation_cancelled'), Envelope::create('ReservationCancelled', $data));
        } catch (PublishFailed $e) {
            throw ApiException::serviceUnavailable('Annulation impossible pour le moment : '.$e->getMessage(), $e);
        }
    }

    /** Best effort (docs/events/README.md) : un échec est seulement journalisé, la réservation reste COMPLETED. */
    public function reservationCompleted(Reservation $reservation): void
    {
        $envelope = Envelope::create('ReservationCompleted', [
            'reservationId' => $reservation->id,
            'vehicleId' => $reservation->vehicle_id,
            'categoryId' => $reservation->category_id,
            'agencyId' => $reservation->pickup_agency_id,
            'startDate' => $reservation->start_date->toDateString(),
            'endDate' => $reservation->end_date->toDateString(),
            'totalPrice' => Json::amount($reservation->total_price),
            'bookedAt' => $reservation->created_at->copy()->utc()->toIso8601ZuluString(),
        ]);
        try {
            $this->publisher->publish(config('rabbitmq.publish.reservation_completed'), $envelope);
        } catch (PublishFailed $e) {
            Log::warning("ReservationCompleted {$reservation->id} non publié (best effort) : ".$e->getMessage());
        }
    }
}
