package com.location.loyaltyservice.promo.dto;

import io.swagger.v3.oas.annotations.media.Schema;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Positive;
import java.math.BigDecimal;

@Schema(description = "Aperçu d'un code pour le client connecté (rien n'est consommé)")
public record CheckRequest(
        @NotBlank @Schema(example = "BIENVENUE10") String code,
        @NotNull @Positive @Schema(example = "450.000") BigDecimal amount) {
}
