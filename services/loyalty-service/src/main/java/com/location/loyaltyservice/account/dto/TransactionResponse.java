package com.location.loyaltyservice.account.dto;

import com.location.loyaltyservice.account.PointTransaction;
import com.location.loyaltyservice.account.TransactionType;
import io.swagger.v3.oas.annotations.media.Schema;
import java.time.Instant;
import java.util.UUID;

@Schema(description = "Ligne de l'historique des points")
public record TransactionResponse(
        UUID id,
        TransactionType type,
        @Schema(example = "80", description = "Positif (gain) ou négatif (dépense)") int points,
        @Schema(example = "Location terminée (640.000 TND, niveau SILVER)") String reason,
        @Schema(description = "Pour EARN : la réservation terminée") UUID reservationId,
        Instant createdAt) {

    public static TransactionResponse from(PointTransaction t) {
        return new TransactionResponse(t.getId(), t.getType(), t.getPoints(), t.getReason(), t.getReservationId(),
                t.getCreatedAt());
    }
}
