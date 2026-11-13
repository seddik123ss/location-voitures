package com.location.loyaltyservice.promo;

import java.math.BigDecimal;
import java.math.RoundingMode;

/**
 * Calcul d'une réduction : PERCENT = montant x valeur / 100, AMOUNT = valeur fixe.
 * La réduction ne dépasse jamais le montant (amountAfter &gt;= 0.000) ; arrondi à 3 décimales (TND).
 */
public record Discount(BigDecimal amountBefore, BigDecimal discount, BigDecimal amountAfter) {

    public static Discount compute(DiscountType type, BigDecimal value, BigDecimal amount) {
        BigDecimal before = amount.setScale(3, RoundingMode.HALF_UP);
        BigDecimal raw = type == DiscountType.PERCENT
                ? before.multiply(value).divide(BigDecimal.valueOf(100), 3, RoundingMode.HALF_UP)
                : value.setScale(3, RoundingMode.HALF_UP);
        BigDecimal discount = raw.min(before);
        return new Discount(before, discount, before.subtract(discount));
    }
}
