package com.location.loyaltyservice.promo.dto;

import com.location.loyaltyservice.account.Tier;
import com.location.loyaltyservice.promo.DiscountType;
import io.swagger.v3.oas.annotations.media.Schema;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;
import java.math.BigDecimal;
import java.time.LocalDate;

/** Création d'un code promo (ADMIN). Champ absent -> 400 ; valeur présente mais invalide -> 422. */
@Schema(description = "Création d'un code promo")
public record PromoCodeRequest(
        @NotBlank @Schema(example = "PRINTEMPS20", description = "A-Z, 0-9 et tiret, 4 à 20 caractères (mis en majuscules)")
        String code,
        @NotNull @Schema(example = "PERCENT") DiscountType discountType,
        @NotNull @Schema(example = "20", description = "PERCENT : 1 à 50 ; AMOUNT : montant en TND > 0") BigDecimal discountValue,
        @Schema(example = "100.000", description = "Montant minimum de la réservation (facultatif)") BigDecimal minAmount,
        @Schema(example = "SILVER", description = "Niveau minimum (facultatif)") Tier minTier,
        @NotNull @Schema(example = "2026-12-01") LocalDate validFrom,
        @NotNull @Schema(example = "2027-03-31", description = "Inclus ; doit être après validFrom") LocalDate validUntil,
        @Schema(example = "500", description = "Nombre total d'utilisations (absent = illimité)") Integer maxUses,
        @Schema(example = "1", description = "Utilisations par client (1 par défaut)") Integer maxUsesPerUser) {
}
