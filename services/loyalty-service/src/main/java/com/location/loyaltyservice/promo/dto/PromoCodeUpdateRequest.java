package com.location.loyaltyservice.promo.dto;

import com.location.loyaltyservice.account.Tier;
import com.location.loyaltyservice.promo.DiscountType;
import io.swagger.v3.oas.annotations.media.Schema;
import jakarta.validation.constraints.NotNull;
import java.math.BigDecimal;
import java.time.LocalDate;

/** Modification d'un code promo (ADMIN). Le code lui-même et le compteur d'utilisations ne changent pas. */
@Schema(description = "Modification d'un code promo (active=false pour le désactiver)")
public record PromoCodeUpdateRequest(
        @NotNull @Schema(example = "PERCENT") DiscountType discountType,
        @NotNull @Schema(example = "15") BigDecimal discountValue,
        @Schema(example = "100.000") BigDecimal minAmount,
        @Schema(example = "SILVER") Tier minTier,
        @NotNull @Schema(example = "2026-12-01") LocalDate validFrom,
        @NotNull @Schema(example = "2027-03-31") LocalDate validUntil,
        @Schema(example = "500") Integer maxUses,
        @Schema(example = "1") Integer maxUsesPerUser,
        @Schema(example = "true", description = "Absent = true") Boolean active) {
}
