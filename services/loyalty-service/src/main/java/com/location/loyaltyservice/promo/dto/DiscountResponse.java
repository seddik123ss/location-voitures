package com.location.loyaltyservice.promo.dto;

import com.location.loyaltyservice.promo.PromoCodeService;
import io.swagger.v3.oas.annotations.media.Schema;
import java.math.BigDecimal;

@Schema(description = "Réduction calculée : le prix barré (amountBefore) puis le nouveau prix (amountAfter)")
public record DiscountResponse(
        @Schema(example = "BIENVENUE10") String code,
        @Schema(example = "450.000") BigDecimal amountBefore,
        @Schema(example = "45.000") BigDecimal discount,
        @Schema(example = "405.000") BigDecimal amountAfter) {

    public static DiscountResponse from(PromoCodeService.Evaluation e) {
        return new DiscountResponse(e.code().getCode(), e.discount().amountBefore(), e.discount().discount(),
                e.discount().amountAfter());
    }
}
