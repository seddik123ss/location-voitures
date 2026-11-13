package com.location.loyaltyservice.account.dto;

import com.location.loyaltyservice.account.AccountStatus;
import com.location.loyaltyservice.account.LoyaltyAccount;
import com.location.loyaltyservice.account.Tier;
import io.swagger.v3.oas.annotations.media.Schema;
import java.time.Instant;
import java.util.UUID;

@Schema(description = "Compte de fidélité : solde, cumul, niveau et progression vers le niveau suivant")
public record AccountResponse(
        @Schema(example = "f4d1bab3-468d-428d-b02c-b44341430bfd") UUID userId,
        @Schema(example = "1240", description = "Points disponibles") int balance,
        @Schema(example = "1640", description = "Total gagné depuis l'ouverture (sert au niveau)") int lifetimePoints,
        Tier tier,
        AccountStatus status,
        @Schema(description = "Niveau suivant, null si GOLD") Tier nextTier,
        @Schema(example = "0", description = "Points de cumul manquants pour le niveau suivant (0 si GOLD)") int pointsToNextTier,
        @Schema(example = "0.15", description = "Progression vers le niveau suivant, de 0 à 1 (1 si GOLD)") double progress,
        Instant createdAt,
        Instant updatedAt) {

    public static AccountResponse from(LoyaltyAccount a) {
        Tier next = a.getTier().next();
        int missing = next == null ? 0 : Math.max(0, next.threshold() - a.getLifetimePoints());
        double progress = next == null ? 1.0
                : Math.min(1.0, (double) (a.getLifetimePoints() - a.getTier().threshold())
                        / (next.threshold() - a.getTier().threshold()));
        return new AccountResponse(a.getUserId(), a.getBalance(), a.getLifetimePoints(), a.getTier(), a.getStatus(),
                next, missing, Math.round(progress * 100) / 100.0, a.getCreatedAt(), a.getUpdatedAt());
    }
}
