package com.location.userservice.reservation;

import com.location.userservice.common.PageResponse;
import java.util.UUID;

/**
 * Source de l'historique des réservations d'un client.
 * Deux implémentations, choisies par la propriété app.reservations.mock (variable RESERVATIONS_MOCK) :
 * {@link FeignReservationHistory} (vrai appel) et {@link MockReservationHistory} (simulation).
 */
public interface ReservationHistory {

    PageResponse<ReservationDto> findByUserId(UUID userId, int page, int size);
}
