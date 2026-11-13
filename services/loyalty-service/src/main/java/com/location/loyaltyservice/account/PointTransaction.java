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

/** Ligne de l'historique des points (jamais modifiée ni supprimée). */
@Entity
@Table(name = "point_transactions")
public class PointTransaction {

    @Id
    @Column(length = 36)
    private UUID id;

    @Column(nullable = false, length = 36)
    private UUID userId;

    @Enumerated(EnumType.STRING)
    @JdbcTypeCode(SqlTypes.VARCHAR)
    @Column(nullable = false, length = 10)
    private TransactionType type;

    @Column(nullable = false)
    private int points;

    @Column(nullable = false)
    private String reason;

    @Column(length = 36)
    private UUID reservationId;

    @Column(nullable = false)
    private Instant createdAt;

    protected PointTransaction() {
    }

    public PointTransaction(UUID userId, TransactionType type, int points, String reason, UUID reservationId,
                            Instant createdAt) {
        this.id = UUID.randomUUID();
        this.userId = userId;
        this.type = type;
        this.points = points;
        this.reason = reason;
        this.reservationId = reservationId;
        this.createdAt = createdAt;
    }

    public UUID getId() {
        return id;
    }

    public UUID getUserId() {
        return userId;
    }

    public TransactionType getType() {
        return type;
    }

    public int getPoints() {
        return points;
    }

    public String getReason() {
        return reason;
    }

    public UUID getReservationId() {
        return reservationId;
    }

    public Instant getCreatedAt() {
        return createdAt;
    }
}
