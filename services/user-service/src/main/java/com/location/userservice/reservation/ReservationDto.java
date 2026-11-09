package com.location.userservice.reservation;

import io.swagger.v3.oas.annotations.media.Schema;
import java.math.BigDecimal;
import java.time.LocalDate;
import java.util.UUID;

/**
 * Réservation telle que renvoyée par reservation-service (docs/internal-api.md) :
 * {id, userId, vehicleId, pickupAgencyId, returnAgencyId, startDate, endDate, status, totalPrice, depositAmount, currency}
 */
@Schema(description = "Réservation (copie des données de reservation-service)")
public record ReservationDto(
        @Schema(example = "33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8") UUID id,
        @Schema(example = "f4d1bab3-468d-428d-b02c-b44341430bfd") UUID userId,
        @Schema(example = "9f03ab2d-97a5-4c08-a952-dc6e446370d1") UUID vehicleId,
        @Schema(example = "eb825ac4-855b-499b-b37a-b4c37bbd6b4e") UUID pickupAgencyId,
        @Schema(example = "eb825ac4-855b-499b-b37a-b4c37bbd6b4e") UUID returnAgencyId,
        @Schema(example = "2026-11-20") LocalDate startDate,
        @Schema(example = "2026-11-25") LocalDate endDate,
        @Schema(description = "PENDING, CONFIRMED, ONGOING, COMPLETED, CANCELLED", example = "CONFIRMED") String status,
        @Schema(example = "450.000") BigDecimal totalPrice,
        @Schema(example = "500.000") BigDecimal depositAmount,
        @Schema(example = "TND") String currency) {
}
