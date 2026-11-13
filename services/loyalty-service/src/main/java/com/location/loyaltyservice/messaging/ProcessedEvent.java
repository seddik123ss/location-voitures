package com.location.loyaltyservice.messaging;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import java.time.Instant;
import java.util.UUID;

/** eventId d'un message RabbitMQ déjà traité : un second envoi du même message est ignoré. */
@Entity
@Table(name = "processed_events")
public class ProcessedEvent {

    @Id
    @Column(length = 36)
    private UUID eventId;

    @Column(nullable = false, length = 40)
    private String type;

    @Column(nullable = false)
    private Instant processedAt;

    protected ProcessedEvent() {
    }

    public ProcessedEvent(UUID eventId, String type, Instant processedAt) {
        this.eventId = eventId;
        this.type = type;
        this.processedAt = processedAt;
    }

    public UUID getEventId() {
        return eventId;
    }

    public String getType() {
        return type;
    }

    public Instant getProcessedAt() {
        return processedAt;
    }
}
