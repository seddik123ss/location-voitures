package com.location.loyaltyservice.remote;

import java.math.BigDecimal;
import java.util.UUID;

/** Champs lus dans la réponse de GET /internal/reservations/{id} (les autres sont ignorés). */
public record RemoteReservation(UUID id, UUID userId, String status, BigDecimal totalPrice) {
}
