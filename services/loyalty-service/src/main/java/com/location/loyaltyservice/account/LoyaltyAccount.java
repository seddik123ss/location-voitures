package com.location.loyaltyservice.account;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.EnumType;
import jakarta.persistence.Enumerated;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import java.time.Instant;
import java.util.UUID;
import org.hibernate.annotations.JdbcTypeCode;
import org.hibernate.type.SqlTypes;

/** Compte de fidélité d'un client : userId = "sub" Keycloak = id dans user-service. */
@Entity
@Table(name = "loyalty_accounts")
public class LoyaltyAccount {

    @Id
    @Column(length = 36)
    private UUID userId;

    @Column(nullable = false)
    private int balance;

    @Column(nullable = false)
    private int lifetimePoints;

    @Enumerated(EnumType.STRING)
    @JdbcTypeCode(SqlTypes.VARCHAR)
    @Column(nullable = false, length = 10)
    private Tier tier;

    @Enumerated(EnumType.STRING)
    @JdbcTypeCode(SqlTypes.VARCHAR)
    @Column(nullable = false, length = 10)
    private AccountStatus status;

    @Column(nullable = false)
    private Instant createdAt;

    @Column(nullable = false)
    private Instant updatedAt;

    protected LoyaltyAccount() {
    }

    public static LoyaltyAccount open(UUID userId, Instant now) {
        LoyaltyAccount account = new LoyaltyAccount();
        account.userId = userId;
        account.balance = 0;
        account.lifetimePoints = 0;
        account.tier = Tier.BRONZE;
        account.status = AccountStatus.ACTIVE;
        account.createdAt = now;
        account.updatedAt = now;
        return account;
    }

    /** Gain de points : solde et cumul augmentent, le niveau est recalculé (il ne peut que monter). */
    public void earn(int points, Instant now) {
        balance += points;
        lifetimePoints += points;
        tier = Tier.forLifetimePoints(lifetimePoints);
        updatedAt = now;
    }

    /** Dépense ou retrait : seul le solde baisse ; le cumul, donc le niveau, ne bouge pas. */
    public void spend(int points, Instant now) {
        balance -= points;
        updatedAt = now;
    }

    public void close(Instant now) {
        status = AccountStatus.CLOSED;
        updatedAt = now;
    }

    public boolean isActive() {
        return status == AccountStatus.ACTIVE;
    }

    public UUID getUserId() {
        return userId;
    }

    public int getBalance() {
        return balance;
    }

    public int getLifetimePoints() {
        return lifetimePoints;
    }

    public Tier getTier() {
        return tier;
    }

    public AccountStatus getStatus() {
        return status;
    }

    public Instant getCreatedAt() {
        return createdAt;
    }

    public Instant getUpdatedAt() {
        return updatedAt;
    }
}
