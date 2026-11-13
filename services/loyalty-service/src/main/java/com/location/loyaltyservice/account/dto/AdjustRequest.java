package com.location.loyaltyservice.account.dto;

import io.swagger.v3.oas.annotations.media.Schema;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Size;

@Schema(description = "Ajustement par un ADMIN : positif (geste commercial, compte dans le cumul) ou négatif (correction)")
public record AdjustRequest(
        @NotNull @Schema(example = "50") Integer points,
        @NotBlank @Size(max = 255) @Schema(example = "Geste commercial : retard de livraison du véhicule") String reason) {
}
