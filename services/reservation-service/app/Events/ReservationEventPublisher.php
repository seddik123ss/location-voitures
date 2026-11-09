<?php

namespace App\Events;

use App\Models\Reservation;
use Carbon\CarbonInterface;

/**
 * Messages publiés par le service (docs/events/README.md). Branché sur RabbitMQ à l'étape 5.
 * Appelé dans la transaction du changement de statut : si la publication échoue, le changement est annulé.
 */
interface ReservationEventPublisher
{
    /** ReservationCancelled vers payment.reservation_cancelled (payment rembourse les paiements réussis). */
    public function reservationCancelled(Reservation $reservation, CarbonInterface $cancelledAt, ?string $reason): void;

    /** ReservationCompleted vers ml.reservation_completed (jeu d'entraînement du modèle de prix). */
    public function reservationCompleted(Reservation $reservation): void;
}
