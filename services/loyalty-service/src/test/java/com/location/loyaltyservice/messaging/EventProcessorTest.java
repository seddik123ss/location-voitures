package com.location.loyaltyservice.messaging;

import static com.location.loyaltyservice.ApiTestSupport.CLIENT1;
import static com.location.loyaltyservice.ApiTestSupport.CLIENT2;
import static com.location.loyaltyservice.ApiTestSupport.NEW_CLIENT;
import static com.location.loyaltyservice.ApiTestSupport.R_COMPLETED;
import static com.location.loyaltyservice.ApiTestSupport.httpError;
import static com.location.loyaltyservice.ApiTestSupport.unreachable;
import static org.assertj.core.api.Assertions.assertThat;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.Mockito.when;

import com.location.loyaltyservice.IntegrationTestBase;
import com.location.loyaltyservice.account.AccountStatus;
import com.location.loyaltyservice.account.LoyaltyAccount;
import com.location.loyaltyservice.account.LoyaltyAccountRepository;
import com.location.loyaltyservice.account.PointTransactionRepository;
import com.location.loyaltyservice.account.Tier;
import com.location.loyaltyservice.promo.PromoCodeRepository;
import com.location.loyaltyservice.remote.RemoteReservation;
import java.io.IOException;
import java.math.BigDecimal;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.UUID;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;

/** Consommateur RabbitMQ (section 8), testé en appelant le traitement directement avec le JSON des messages. */
class EventProcessorTest extends IntegrationTestBase {

    @Autowired
    private EventProcessor processor;
    @Autowired
    private LoyaltyAccountRepository accounts;
    @Autowired
    private PointTransactionRepository transactions;
    @Autowired
    private PromoCodeRepository promoCodes;

    private static byte[] userCreated(String eventId, String userId, String role) {
        return ("{\"eventId\":\"" + eventId + "\",\"type\":\"UserCreated\",\"occurredAt\":\"2026-11-20T09:00:00Z\","
                + "\"data\":{\"userId\":\"" + userId + "\",\"email\":\"nouveau@test.tn\",\"firstname\":\"Nour\","
                + "\"lastname\":\"Ben Salah\",\"role\":\"" + role + "\"}}").getBytes(StandardCharsets.UTF_8);
    }

    /** Exemple officiel docs/events/ReservationCompleted.json (r-completed, client2, 640 TND). */
    private static byte[] officialReservationCompleted() throws IOException {
        return Files.readAllBytes(Path.of("..", "..", "docs", "events", "ReservationCompleted.json"));
    }

    private void reservationOf(String userId) {
        when(reservationClient.getReservation(any())).thenReturn(new RemoteReservation(UUID.fromString(R_COMPLETED),
                UUID.fromString(userId), "COMPLETED", new BigDecimal("640.000")));
    }

    @Test
    void userCreatedOpensTheAccountWith100Points() {
        Outcome outcome = processor.process(RabbitConfig.USER_EVENTS,
                userCreated(UUID.randomUUID().toString(), NEW_CLIENT, "CLIENT"), false);

        assertThat(outcome).isEqualTo(Outcome.ACK);
        LoyaltyAccount account = accounts.findById(UUID.fromString(NEW_CLIENT)).orElseThrow();
        assertThat(account.getBalance()).isEqualTo(100);
        assertThat(account.getTier()).isEqualTo(Tier.BRONZE);
        assertThat(transactions.findByUserIdOrderByCreatedAtDesc(UUID.fromString(NEW_CLIENT),
                org.springframework.data.domain.Pageable.unpaged()).getContent())
                .singleElement().satisfies(t -> assertThat(t.getPoints()).isEqualTo(100));
    }

    @Test
    void sameEventIdTwiceHasOneEffect() {
        String eventId = UUID.randomUUID().toString();
        processor.process(RabbitConfig.USER_EVENTS, userCreated(eventId, NEW_CLIENT, "CLIENT"), false);
        accounts.findById(UUID.fromString(NEW_CLIENT)).orElseThrow();

        Outcome second = processor.process(RabbitConfig.USER_EVENTS, userCreated(eventId, NEW_CLIENT, "CLIENT"), false);

        assertThat(second).isEqualTo(Outcome.ACK);
        assertThat(processor.summary()).contains("doublon");
        assertThat(accounts.findById(UUID.fromString(NEW_CLIENT)).orElseThrow().getBalance()).isEqualTo(100);
    }

    @Test
    void userCreatedForAnAgentOpensNoAccount() {
        Outcome outcome = processor.process(RabbitConfig.USER_EVENTS,
                userCreated(UUID.randomUUID().toString(), NEW_CLIENT, "AGENT"), false);

        assertThat(outcome).isEqualTo(Outcome.ACK);
        assertThat(accounts.existsById(UUID.fromString(NEW_CLIENT))).isFalse();
    }

    @Test
    void userDeletedClosesTheAccountAndDisablesVouchers() {
        byte[] body = ("{\"eventId\":\"" + UUID.randomUUID() + "\",\"type\":\"UserDeleted\","
                + "\"occurredAt\":\"2026-11-20T09:00:00Z\",\"data\":{\"userId\":\"" + CLIENT1
                + "\",\"deletedAt\":\"2026-11-20T09:00:00Z\"}}").getBytes(StandardCharsets.UTF_8);

        assertThat(processor.process(RabbitConfig.USER_EVENTS, body, false)).isEqualTo(Outcome.ACK);
        assertThat(accounts.findById(UUID.fromString(CLIENT1)).orElseThrow().getStatus()).isEqualTo(AccountStatus.CLOSED);
        assertThat(promoCodes.findByCode("LOY-DEMO40").orElseThrow().isActive()).isFalse();
    }

    @Test
    void reservationCompletedAddsPointsWithTheTierCoefficient() throws IOException {
        reservationOf(CLIENT2);   // l'événement ne contient pas userId : OpenFeign vers reservation-service

        Outcome outcome = processor.process(RabbitConfig.RESERVATION_COMPLETED, officialReservationCompleted(), false);

        assertThat(outcome).isEqualTo(Outcome.ACK);
        LoyaltyAccount client2 = accounts.findById(UUID.fromString(CLIENT2)).orElseThrow();
        assertThat(client2.getBalance()).isEqualTo(180 + 64);          // 640 TND en BRONZE : floor(64) x 1
        assertThat(client2.getLifetimePoints()).isEqualTo(244);
    }

    @Test
    void goldClientEarnsOneAndAHalfTimes() throws IOException {
        reservationOf(CLIENT1);
        processor.process(RabbitConfig.RESERVATION_COMPLETED, officialReservationCompleted(), false);
        assertThat(accounts.findById(UUID.fromString(CLIENT1)).orElseThrow().getBalance()).isEqualTo(1240 + 96);
    }

    @Test
    void sameReservationReceivedTwiceCountsOnce() throws IOException {
        reservationOf(CLIENT2);
        processor.process(RabbitConfig.RESERVATION_COMPLETED, officialReservationCompleted(), false);
        // même réservation, autre eventId (message republié par le producteur)
        String republished = new String(officialReservationCompleted(), StandardCharsets.UTF_8)
                .replace("1f11e045-cd86-4fea-9c30-a16f7ddb75ee", UUID.randomUUID().toString());

        Outcome outcome = processor.process(RabbitConfig.RESERVATION_COMPLETED,
                republished.getBytes(StandardCharsets.UTF_8), false);

        assertThat(outcome).isEqualTo(Outcome.ACK);
        assertThat(processor.summary()).contains("déjà comptée");
        assertThat(accounts.findById(UUID.fromString(CLIENT2)).orElseThrow().getBalance()).isEqualTo(244);
    }

    @Test
    void unreadableMessageGoesToTheDeadLetterQueue() {
        assertThat(processor.process(RabbitConfig.USER_EVENTS, "{not json".getBytes(StandardCharsets.UTF_8), false))
                .isEqualTo(Outcome.DEAD_LETTER);
        assertThat(processor.process(RabbitConfig.USER_EVENTS,
                "{\"eventId\":\"123\",\"type\":\"UserCreated\",\"data\":{}}".getBytes(StandardCharsets.UTF_8), false))
                .isEqualTo(Outcome.DEAD_LETTER);
    }

    @Test
    void wrongTypeOnAQueueGoesToTheDeadLetterQueue() throws IOException {
        assertThat(processor.process(RabbitConfig.USER_EVENTS, officialReservationCompleted(), false))
                .isEqualTo(Outcome.DEAD_LETTER);
    }

    @Test
    void reservationServiceDownIsRetriedOnceThenDeadLettered() throws IOException {
        when(reservationClient.getReservation(any())).thenThrow(unreachable());

        assertThat(processor.process(RabbitConfig.RESERVATION_COMPLETED, officialReservationCompleted(), false))
                .isEqualTo(Outcome.REQUEUE);
        assertThat(processor.process(RabbitConfig.RESERVATION_COMPLETED, officialReservationCompleted(), true))
                .isEqualTo(Outcome.DEAD_LETTER);
        assertThat(accounts.findById(UUID.fromString(CLIENT2)).orElseThrow().getBalance()).isEqualTo(180);
    }

    @Test
    void unknownReservationGoesToTheDeadLetterQueue() throws IOException {
        when(reservationClient.getReservation(any())).thenThrow(httpError(404));

        assertThat(processor.process(RabbitConfig.RESERVATION_COMPLETED, officialReservationCompleted(), false))
                .isEqualTo(Outcome.DEAD_LETTER);
    }
}
