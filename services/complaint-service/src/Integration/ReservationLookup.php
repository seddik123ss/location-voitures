<?php

namespace App\Integration;

use App\Exception\ApiException;

/** Accès à RENTAL-RESERVATION (GET /internal/reservations/{id}). */
interface ReservationLookup
{
    /**
     * @return ReservationInfo|null null si la réservation n'existe pas (404 du service distant)
     *
     * @throws ApiException 503 si le service ou Eureka est indisponible, 502 si la réponse est illisible
     */
    public function find(string $reservationId): ?ReservationInfo;
}
