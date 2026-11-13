package com.location.loyaltyservice.messaging;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.location.loyaltyservice.account.LoyaltyService;
import com.location.loyaltyservice.remote.RemoteReservation;
import com.location.loyaltyservice.remote.RemoteServices;
import java.io.IOException;
import java.math.BigDecimal;
import java.time.Clock;
import java.time.Instant;
import java.util.Map;
import java.util.Set;
import java.util.UUID;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Component;
import org.springframework.transaction.support.TransactionTemplate;

/**
 * Traitement d'un message RabbitMQ, sans dépendance à RabbitMQ (testable en appelant {@link #process} directement).
 *
 * <ul>
 *   <li>enveloppe commune {eventId, type, occurredAt, data} illisible, type inattendu, champ absent -&gt; DEAD_LETTER ;</li>
 *   <li>eventId déjà traité -&gt; ACK sans rien refaire (idempotence) ;</li>
 *   <li>effets + eventId enregistrés dans la même transaction, ACK seulement après le commit ;</li>
 *   <li>autre erreur (base, user-service ou reservation-service indisponible) : première fois REQUEUE,
 *       message déjà remis en file DEAD_LETTER.</li>
 * </ul>
 */
@Component
public class EventProcessor {

    private static final Logger log = LoggerFactory.getLogger(EventProcessor.class);

    static final Map<String, Set<String>> EXPECTED_TYPES = Map.of(
            RabbitConfig.USER_EVENTS, Set.of("UserCreated", "UserDeleted"),
            RabbitConfig.RESERVATION_COMPLETED, Set.of("ReservationCompleted"));

    private final ObjectMapper objectMapper;
    private final LoyaltyService loyalty;
    private final RemoteServices remote;
    private final ProcessedEventRepository processedEvents;
    private final TransactionTemplate transaction;
    private final Clock clock;

    /** Résumé du dernier traitement (journal et tests). */
    private volatile String summary = "";

    public EventProcessor(ObjectMapper objectMapper, LoyaltyService loyalty, RemoteServices remote,
                          ProcessedEventRepository processedEvents, TransactionTemplate transaction, Clock clock) {
        this.objectMapper = objectMapper;
        this.loyalty = loyalty;
        this.remote = remote;
        this.processedEvents = processedEvents;
        this.transaction = transaction;
        this.clock = clock;
    }

    public String summary() {
        return summary;
    }

    public Outcome process(String queue, byte[] body, boolean redelivered) {
        Envelope envelope;
        try {
            envelope = parse(queue, body);
        } catch (InvalidMessage ex) {
            summary = "Message rejeté vers " + queue + ".dlq : " + ex.getMessage();
            log.error(summary);
            return Outcome.DEAD_LETTER;
        }
        try {
            String result = transaction.execute(status -> {
                if (processedEvents.existsById(envelope.eventId())) {
                    return "doublon ignoré";
                }
                String effect = handle(envelope);
                processedEvents.save(new ProcessedEvent(envelope.eventId(), envelope.type(), Instant.now(clock)));
                return effect;
            });
            summary = envelope.type() + " " + envelope.eventId() + " : " + result;
            log.info("[{}] {}", queue, summary);
            return Outcome.ACK;
        } catch (InvalidMessage ex) {
            summary = envelope.type() + " " + envelope.eventId() + " rejeté vers " + queue + ".dlq : " + ex.getMessage();
            log.error(summary);
            return Outcome.DEAD_LETTER;
        } catch (RuntimeException ex) {
            if (redelivered) {
                summary = envelope.type() + " " + envelope.eventId() + " : nouvel échec après remise en file, envoyé dans "
                        + queue + ".dlq : " + ex.getMessage();
                log.error(summary, ex);
                return Outcome.DEAD_LETTER;
            }
            summary = envelope.type() + " " + envelope.eventId() + " : échec, remis en file une fois : " + ex.getMessage();
            log.warn(summary);
            return Outcome.REQUEUE;
        }
    }

    record Envelope(UUID eventId, String type, JsonNode data) {
    }

    private Envelope parse(String queue, byte[] body) {
        JsonNode root;
        try {
            root = objectMapper.readTree(body);
        } catch (IOException ex) {
            throw new InvalidMessage("JSON invalide");
        }
        if (root == null || !root.isObject()) {
            throw new InvalidMessage("Objet JSON attendu");
        }
        UUID eventId = uuid(root, "eventId");
        String type = root.path("type").asText("");
        if (type.isBlank()) {
            throw new InvalidMessage("type absent");
        }
        if (!EXPECTED_TYPES.getOrDefault(queue, Set.of()).contains(type)) {
            throw new InvalidMessage("type " + type + " inattendu sur la file " + queue);
        }
        JsonNode data = root.get("data");
        if (data == null || !data.isObject()) {
            throw new InvalidMessage("data absent ou qui n'est pas un objet");
        }
        return new Envelope(eventId, type, data);
    }

    private String handle(Envelope e) {
        return switch (e.type()) {
            case "UserCreated" -> userCreated(e.data());
            case "UserDeleted" -> userDeleted(e.data());
            case "ReservationCompleted" -> reservationCompleted(e.data());
            default -> throw new InvalidMessage("type " + e.type() + " non géré");
        };
    }

    /** UserCreated : compte ouvert avec 100 points, pour le rôle CLIENT seulement. */
    private String userCreated(JsonNode data) {
        UUID userId = uuid(data, "userId");
        String role = data.path("role").asText("");
        if (!"CLIENT".equals(role)) {
            return "ignoré : rôle " + (role.isBlank() ? "absent" : role) + " (compte réservé aux clients)";
        }
        return loyalty.openAccount(userId) ? "compte ouvert (+100 points)" : "compte déjà ouvert";
    }

    /** UserDeleted : compte passé à CLOSED et bons désactivés. */
    private String userDeleted(JsonNode data) {
        UUID userId = uuid(data, "userId");
        return loyalty.closeAccount(userId) ? "compte fermé, bons désactivés" : "ignoré : aucun compte";
    }

    /** ReservationCompleted : le client est retrouvé par OpenFeign (l'événement ne contient pas userId). */
    private String reservationCompleted(JsonNode data) {
        UUID reservationId = uuid(data, "reservationId");
        RemoteReservation reservation = remote.findReservation(reservationId)
                .orElseThrow(() -> new InvalidMessage("Réservation " + reservationId + " inconnue de reservation-service"));
        BigDecimal totalPrice = data.hasNonNull("totalPrice") && data.get("totalPrice").isNumber()
                ? data.get("totalPrice").decimalValue()
                : reservation.totalPrice();
        if (totalPrice == null) {
            throw new InvalidMessage("totalPrice absent");
        }
        return switch (loyalty.earnForReservation(reservationId, reservation.userId(), totalPrice)) {
            case EARNED -> "points ajoutés au client " + reservation.userId();
            case ALREADY_COUNTED -> "réservation déjà comptée";
            case NO_ACTIVE_ACCOUNT -> "ignoré : pas de compte de fidélité actif pour " + reservation.userId();
            case NOTHING_TO_EARN -> "aucun point (montant trop faible)";
        };
    }

    private static UUID uuid(JsonNode node, String field) {
        String value = node.path(field).asText("");
        try {
            return UUID.fromString(value);
        } catch (IllegalArgumentException ex) {
            throw new InvalidMessage(field + " absent ou qui n'est pas un UUID");
        }
    }
}
