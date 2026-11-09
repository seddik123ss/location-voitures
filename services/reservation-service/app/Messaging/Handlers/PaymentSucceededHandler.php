<?php

namespace App\Messaging\Handlers;

use App\Enums\ReservationStatus;
use App\Messaging\Envelope;
use App\Messaging\InvalidMessage;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * reservation.payment_succeeded (payment-service) :
 * data = {paymentId, reservationId, userId, amount, currency, type}. Seul type = RENTAL confirme la réservation
 * (PENDING -> CONFIRMED) ; DEPOSIT et EXTRA_FEE sont ignorés.
 */
final class PaymentSucceededHandler implements MessageHandler
{
    public function __construct(private readonly ReservationService $reservations)
    {
    }

    public function type(): string
    {
        return 'PaymentSucceeded';
    }

    public function handle(Envelope $envelope): string
    {
        $data = $envelope->data;
        $paymentType = $data['type'] ?? null;
        if (! is_string($paymentType)) {
            throw new InvalidMessage('data.type absent');
        }
        if ($paymentType !== 'RENTAL') {
            return "ignoré : paiement $paymentType";
        }

        $reservation = self::reservation($data);
        $paymentId = is_string($data['paymentId'] ?? null) ? $data['paymentId'] : '?';

        // payment-service vérifie déjà que le montant RENTAL = totalPrice ; un écart est seulement signalé.
        if (is_numeric($data['amount'] ?? null) && bccomp((string) $data['amount'], $reservation->total_price, 3) !== 0) {
            Log::warning("PaymentSucceeded $paymentId : montant {$data['amount']} différent du prix {$reservation->total_price} de la réservation {$reservation->id}");
        }

        return match ($reservation->status) {
            ReservationStatus::PENDING => tap('PENDING -> CONFIRMED', fn () => $this->reservations->changeStatus(
                $reservation,
                ReservationStatus::CONFIRMED,
                null,
                "Paiement RENTAL $paymentId reçu",
            )),
            ReservationStatus::CANCELLED => tap('ignoré : réservation CANCELLED', fn () => Log::warning(
                "Paiement RENTAL $paymentId reçu pour la réservation annulée {$reservation->id} : payment-service doit le rembourser",
            )),
            default => "ignoré : réservation déjà {$reservation->status->value}",
        };
    }

    /** @throws InvalidMessage */
    public static function reservation(array $data): Reservation
    {
        $id = $data['reservationId'] ?? null;
        if (! is_string($id) || ! Str::isUuid($id)) {
            throw new InvalidMessage('data.reservationId absent ou qui n\'est pas un UUID');
        }
        $reservation = Reservation::query()->find(strtolower($id));
        if ($reservation === null) {
            throw new InvalidMessage("Réservation $id inconnue");
        }

        return $reservation;
    }
}
