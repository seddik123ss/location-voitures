<?php

namespace App\Messaging\Handlers;

use App\Enums\ReservationStatus;
use App\Messaging\Envelope;
use App\Messaging\InvalidMessage;
use App\Services\ReservationService;

/**
 * reservation.contract_closed (contract-service) : data = {contractId, reservationId, closedAt}.
 * La réservation passe en COMPLETED, puis ReservationCompleted part vers ml.reservation_completed (après validation).
 * Si l'agent n'a pas marqué la remise du véhicule (réservation encore CONFIRMED), le passage par ONGOING est
 * enregistré automatiquement : un contrat clôturé prouve que le véhicule a été remis.
 */
final class ContractClosedHandler implements MessageHandler
{
    public function __construct(private readonly ReservationService $reservations)
    {
    }

    public function type(): string
    {
        return 'ContractClosed';
    }

    public function handle(Envelope $envelope): string
    {
        $reservation = PaymentSucceededHandler::reservation($envelope->data);
        $contractId = is_string($envelope->data['contractId'] ?? null) ? $envelope->data['contractId'] : '?';

        switch ($reservation->status) {
            case ReservationStatus::COMPLETED:
                return 'ignoré : réservation déjà COMPLETED';

            case ReservationStatus::CONFIRMED:
                $reservation = $this->reservations->changeStatus($reservation, ReservationStatus::ONGOING, null, "Remise du véhicule déduite de la clôture du contrat $contractId");
                $this->reservations->changeStatus($reservation, ReservationStatus::COMPLETED, null, "Contrat $contractId clôturé");

                return 'CONFIRMED -> ONGOING -> COMPLETED';

            case ReservationStatus::ONGOING:
                $this->reservations->changeStatus($reservation, ReservationStatus::COMPLETED, null, "Contrat $contractId clôturé");

                return 'ONGOING -> COMPLETED';

            default:
                // PENDING ou CANCELLED : aucun contrat n'aurait dû exister, à examiner dans la .dlq.
                throw new InvalidMessage("Contrat $contractId clôturé pour une réservation {$reservation->status->value}");
        }
    }
}
