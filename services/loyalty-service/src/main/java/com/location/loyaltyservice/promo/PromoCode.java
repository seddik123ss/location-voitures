package com.location.loyaltyservice.promo;

import com.location.loyaltyservice.account.Tier;
import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.EnumType;
import jakarta.persistence.Enumerated;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import java.math.BigDecimal;
import java.time.Instant;
import java.time.LocalDate;
import java.util.UUID;
import org.hibernate.annotations.JdbcTypeCode;
import org.hibernate.type.SqlTypes;

/**
 * Code promo créé par un ADMIN, ou bon personnel LOY-XXXXXX issu d'une conversion de points (ownerUserId renseigné).
 * Validité : de validFrom à validUntil inclus.
 */
@Entity
@Table(name = "promo_codes")
public class PromoCode {

    @Id
    @Column(length = 36)
    private UUID id;

    @Column(nullable = false, unique = true, length = 20)
    private String code;

    @Enumerated(EnumType.STRING)
    @JdbcTypeCode(SqlTypes.VARCHAR)
    @Column(nullable = false, length = 10)
    private DiscountType discountType;

    @Column(nullable = false, precision = 12, scale = 3)
    private BigDecimal discountValue;

    @Column(precision = 12, scale = 3)
    private BigDecimal minAmount;

    @Enumerated(EnumType.STRING)
    @JdbcTypeCode(SqlTypes.VARCHAR)
    @Column(length = 10)
    private Tier minTier;

    @Column(nullable = false)
    private LocalDate validFrom;

    @Column(nullable = false)
    private LocalDate validUntil;

    private Integer maxUses;

    @Column(nullable = false)
    private int maxUsesPerUser;

    @Column(nullable = false)
    private int usedCount;

    @Column(length = 36)
    private UUID ownerUserId;

    @Column(nullable = false)
    private boolean active;

    @Column(nullable = false)
    private Instant createdAt;

    protected PromoCode() {
    }

    public PromoCode(String code, DiscountType discountType, BigDecimal discountValue, BigDecimal minAmount,
                     Tier minTier, LocalDate validFrom, LocalDate validUntil, Integer maxUses, int maxUsesPerUser,
                     UUID ownerUserId, Instant createdAt) {
        this.id = UUID.randomUUID();
        this.code = code;
        this.discountType = discountType;
        this.discountValue = discountValue;
        this.minAmount = minAmount;
        this.minTier = minTier;
        this.validFrom = validFrom;
        this.validUntil = validUntil;
        this.maxUses = maxUses;
        this.maxUsesPerUser = maxUsesPerUser;
        this.usedCount = 0;
        this.ownerUserId = ownerUserId;
        this.active = true;
        this.createdAt = createdAt;
    }

    /** Modification par un ADMIN (le code lui-même et le compteur ne changent pas). */
    public void update(DiscountType discountType, BigDecimal discountValue, BigDecimal minAmount, Tier minTier,
                       LocalDate validFrom, LocalDate validUntil, Integer maxUses, int maxUsesPerUser, boolean active) {
        this.discountType = discountType;
        this.discountValue = discountValue;
        this.minAmount = minAmount;
        this.minTier = minTier;
        this.validFrom = validFrom;
        this.validUntil = validUntil;
        this.maxUses = maxUses;
        this.maxUsesPerUser = maxUsesPerUser;
        this.active = active;
    }

    public void markUsed() {
        usedCount++;
    }

    public void deactivate() {
        active = false;
    }

    public boolean isPersonal() {
        return ownerUserId != null;
    }

    public boolean isExhausted() {
        return maxUses != null && usedCount >= maxUses;
    }

    public UUID getId() {
        return id;
    }

    public String getCode() {
        return code;
    }

    public DiscountType getDiscountType() {
        return discountType;
    }

    public BigDecimal getDiscountValue() {
        return discountValue;
    }

    public BigDecimal getMinAmount() {
        return minAmount;
    }

    public Tier getMinTier() {
        return minTier;
    }

    public LocalDate getValidFrom() {
        return validFrom;
    }

    public LocalDate getValidUntil() {
        return validUntil;
    }

    public Integer getMaxUses() {
        return maxUses;
    }

    public int getMaxUsesPerUser() {
        return maxUsesPerUser;
    }

    public int getUsedCount() {
        return usedCount;
    }

    public UUID getOwnerUserId() {
        return ownerUserId;
    }

    public boolean isActive() {
        return active;
    }

    public Instant getCreatedAt() {
        return createdAt;
    }
}
