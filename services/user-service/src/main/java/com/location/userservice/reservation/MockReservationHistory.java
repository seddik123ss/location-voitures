package com.location.userservice.reservation;

import com.location.userservice.common.PageResponse;
import java.math.BigDecimal;
import java.time.LocalDate;
import java.util.Comparator;
import java.util.List;
import java.util.UUID;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.boot.autoconfigure.condition.ConditionalOnProperty;
import org.springframework.stereotype.Component;

/**
 * Simulation de reservation-service tant que la route d'Aziz n'est pas prête (RESERVATIONS_MOCK=true, par défaut).
 * Données reprises de docs/demo-ids.md (r-pending, r-confirmed, r-completed).
 */
@Component
@ConditionalOnProperty(name = "app.reservations.mock", havingValue = "true", matchIfMissing = true)
public class MockReservationHistory implements ReservationHistory {

    private static final Logger log = LoggerFactory.getLogger(MockReservationHistory.class);

    private static final UUID CLIENT1 = UUID.fromString("f4d1bab3-468d-428d-b02c-b44341430bfd");
    private static final UUID CLIENT2 = UUID.fromString("611f32de-ce7b-4927-b1c4-d113b6479d59");
    private static final UUID AG_TUNIS = UUID.fromString("eb825ac4-855b-499b-b37a-b4c37bbd6b4e");
    private static final UUID AG_SOUSSE = UUID.fromString("1deb1dea-ab54-436d-8ed7-c3322f782c88");

    private static final List<ReservationDto> DEMO = List.of(
            new ReservationDto(UUID.fromString("01cb68ad-4eec-4bdd-8971-ce35381c59d1"), CLIENT1,
                    UUID.fromString("ecdcc60f-ed6a-4007-8622-2b568338ef7a"), AG_TUNIS, AG_TUNIS,
                    LocalDate.parse("2026-12-10"), LocalDate.parse("2026-12-13"), "PENDING",
                    new BigDecimal("270.000"), new BigDecimal("500.000"), "TND"),
            new ReservationDto(UUID.fromString("33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8"), CLIENT1,
                    UUID.fromString("9f03ab2d-97a5-4c08-a952-dc6e446370d1"), AG_TUNIS, AG_TUNIS,
                    LocalDate.parse("2026-11-20"), LocalDate.parse("2026-11-25"), "CONFIRMED",
                    new BigDecimal("450.000"), new BigDecimal("500.000"), "TND"),
            new ReservationDto(UUID.fromString("30eeff32-a413-42fb-9845-8ede4b7f98e0"), CLIENT2,
                    UUID.fromString("a1154300-bea1-4072-b750-2b00cf1f0bcd"), AG_SOUSSE, AG_SOUSSE,
                    LocalDate.parse("2026-10-01"), LocalDate.parse("2026-10-05"), "COMPLETED",
                    new BigDecimal("640.000"), new BigDecimal("1000.000"), "TND"));

    @Override
    public PageResponse<ReservationDto> findByUserId(UUID userId, int page, int size) {
        log.info("[SIMULATION] historique des réservations de {} (RESERVATIONS_MOCK=true)", userId);
        List<ReservationDto> all = DEMO.stream()
                .filter(r -> r.userId().equals(userId))
                .sorted(Comparator.comparing(ReservationDto::startDate).reversed())
                .toList();
        int from = Math.min(page * size, all.size());
        int to = Math.min(from + size, all.size());
        return new PageResponse<>(all.subList(from, to), page, size, all.size());
    }
}
