package com.location.loyaltyservice.promo.dto;

import io.swagger.v3.oas.annotations.media.Schema;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Positive;
import java.math.BigDecimal;
import java.util.UUID;

@Schema(description = "Application d'un code à une réservation par reservation-service (idempotent par reservationId)")
public record ApplyRequest(
        @NotBlank @Schema(example = "BIENVENUE10") String code,
        @NotNull @Schema(example = "f4d1bab3-468d-428d-b02c-b44341430bfd") UUID userId,
        @NotNull @Schema(example = "01cb68ad-4eec-4bdd-8971-ce35381c59d1") UUID reservationId,
        @NotNull @Positive @Schema(example = "270.000") BigDecimal amount) {
}
