package com.location.loyaltyservice.promo.dto;

import com.location.loyaltyservice.account.Tier;
import com.location.loyaltyservice.promo.DiscountType;
import com.location.loyaltyservice.promo.PromoCode;
import io.swagger.v3.oas.annotations.media.Schema;
import java.math.BigDecimal;
import java.time.Instant;
import java.time.LocalDate;
import java.util.UUID;

@Schema(description = "Code promo ou bon personnel")
public record PromoCodeResponse(
        @Schema(example = "0a76460c-0f85-475e-9744-e5d95b3f32b9") UUID id,
        @Schema(example = "BIENVENUE10") String code,
        DiscountType discountType,
        @Schema(example = "10.000") BigDecimal discountValue,
        @Schema(example = "200.000") BigDecimal minAmount,
        Tier minTier,
        @Schema(example = "2026-01-01") LocalDate validFrom,
        @Schema(example = "2027-12-31") LocalDate validUntil,
        @Schema(example = "100") Integer maxUses,
        @Schema(example = "1") int maxUsesPerUser,
        @Schema(example = "12") int usedCount,
        @Schema(description = "Renseigné pour un bon personnel LOY-XXXXXX") UUID ownerUserId,
        @Schema(example = "true") boolean active,
        @Schema(example = "VALID", description = "VALID, UPCOMING, EXPIRED, EXHAUSTED ou INACTIVE (calculé)") String state,
        Instant createdAt) {

    public static PromoCodeResponse from(PromoCode p, LocalDate today) {
        String state = !p.isActive() ? "INACTIVE"
                : today.isAfter(p.getValidUntil()) ? "EXPIRED"
                : today.isBefore(p.getValidFrom()) ? "UPCOMING"
                : p.isExhausted() ? "EXHAUSTED"
                : "VALID";
        return new PromoCodeResponse(p.getId(), p.getCode(), p.getDiscountType(), p.getDiscountValue(),
                p.getMinAmount(), p.getMinTier(), p.getValidFrom(), p.getValidUntil(), p.getMaxUses(),
                p.getMaxUsesPerUser(), p.getUsedCount(), p.getOwnerUserId(), p.isActive(), state, p.getCreatedAt());
    }
}
