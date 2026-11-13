package com.location.loyaltyservice.promo;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import java.math.BigDecimal;
import java.time.Instant;
import java.util.UUID;

/** Utilisation d'un code sur une réservation (une seule par réservation : reservationId unique). */
@Entity
@Table(name = "promo_usages")
public class PromoUsage {

    @Id
    @Column(length = 36)
    private UUID id;

    @Column(nullable = false, length = 36)
    private UUID promoCodeId;

    @Column(nullable = false, length = 36)
    private UUID userId;

    @Column(nullable = false, unique = true, length = 36)
    private UUID reservationId;

    @Column(nullable = false, precision = 12, scale = 3)
    private BigDecimal amountBefore;

    @Column(nullable = false, precision = 12, scale = 3)
    private BigDecimal discount;

    @Column(nullable = false, precision = 12, scale = 3)
    private BigDecimal amountAfter;

    @Column(nullable = false)
    private Instant usedAt;

    protected PromoUsage() {
    }

    public PromoUsage(UUID promoCodeId, UUID userId, UUID reservationId, Discount discount, Instant usedAt) {
        this.id = UUID.randomUUID();
        this.promoCodeId = promoCodeId;
        this.userId = userId;
        this.reservationId = reservationId;
        this.amountBefore = discount.amountBefore();
        this.discount = discount.discount();
        this.amountAfter = discount.amountAfter();
        this.usedAt = usedAt;
    }

    public UUID getId() {
        return id;
    }

    public UUID getPromoCodeId() {
        return promoCodeId;
    }

    public UUID getUserId() {
        return userId;
    }

    public UUID getReservationId() {
        return reservationId;
    }

    public BigDecimal getAmountBefore() {
        return amountBefore;
    }

    public BigDecimal getDiscount() {
        return discount;
    }

    public BigDecimal getAmountAfter() {
        return amountAfter;
    }

    public Instant getUsedAt() {
        return usedAt;
    }
}
