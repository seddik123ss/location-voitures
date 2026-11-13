package com.location.loyaltyservice.promo.dto;

import com.location.loyaltyservice.promo.PromoCode;
import com.location.loyaltyservice.promo.PromoUsage;
import io.swagger.v3.oas.annotations.media.Schema;
import java.math.BigDecimal;
import java.time.Instant;
import java.util.UUID;

@Schema(description = "Utilisation d'un code promo sur une réservation")
public record UsageResponse(
        UUID id,
        @Schema(example = "BIENVENUE10") String code,
        UUID promoCodeId,
        UUID userId,
        UUID reservationId,
        @Schema(example = "270.000") BigDecimal amountBefore,
        @Schema(example = "27.000") BigDecimal discount,
        @Schema(example = "243.000") BigDecimal amountAfter,
        Instant usedAt) {

    public static UsageResponse from(PromoUsage u, PromoCode code) {
        return new UsageResponse(u.getId(), code.getCode(), u.getPromoCodeId(), u.getUserId(), u.getReservationId(),
                u.getAmountBefore(), u.getDiscount(), u.getAmountAfter(), u.getUsedAt());
    }
}
