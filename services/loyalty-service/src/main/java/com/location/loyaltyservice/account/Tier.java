package com.location.loyaltyservice.account;

import java.math.BigDecimal;

/**
 * Niveaux de fidélité, calculés sur le cumul des points gagnés (lifetimePoints), jamais sur le solde :
 * BRONZE de 0 à 499, SILVER de 500 à 1499, GOLD à partir de 1500.
 * Le coefficient multiplie les points gagnés à chaque location terminée.
 */
public enum Tier {
    BRONZE(0, new BigDecimal("1")),
    SILVER(500, new BigDecimal("1.25")),
    GOLD(1500, new BigDecimal("1.5"));

    private final int threshold;
    private final BigDecimal coefficient;

    Tier(int threshold, BigDecimal coefficient) {
        this.threshold = threshold;
        this.coefficient = coefficient;
    }

    public int threshold() {
        return threshold;
    }

    public BigDecimal coefficient() {
        return coefficient;
    }

    /** Niveau correspondant à un cumul de points. */
    public static Tier forLifetimePoints(int lifetimePoints) {
        Tier result = BRONZE;
        for (Tier tier : values()) {
            if (lifetimePoints >= tier.threshold) {
                result = tier;
            }
        }
        return result;
    }

    /** Niveau suivant, ou null pour GOLD. */
    public Tier next() {
        return this == GOLD ? null : values()[ordinal() + 1];
    }

    /** Ce niveau atteint-il au moins le niveau demandé ? (null = aucune exigence) */
    public boolean atLeast(Tier required) {
        return required == null || compareTo(required) >= 0;
    }
}
