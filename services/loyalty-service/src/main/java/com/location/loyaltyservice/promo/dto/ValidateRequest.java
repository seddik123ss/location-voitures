package com.location.loyaltyservice.promo.dto;

import io.swagger.v3.oas.annotations.media.Schema;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Positive;
import java.math.BigDecimal;
import java.util.UUID;

@Schema(description = "Validation d'un code par reservation-service (rien n'est consommé)")
public record ValidateRequest(
        @NotBlank @Schema(example = "BIENVENUE10") String code,
        @NotNull @Schema(example = "f4d1bab3-468d-428d-b02c-b44341430bfd") UUID userId,
        @NotNull @Positive @Schema(example = "450.000") BigDecimal amount) {
}
