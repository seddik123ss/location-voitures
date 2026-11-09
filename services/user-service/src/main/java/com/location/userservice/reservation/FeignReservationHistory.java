package com.location.userservice.reservation;

import com.location.userservice.common.PageResponse;
import java.util.UUID;
import org.springframework.boot.autoconfigure.condition.ConditionalOnProperty;
import org.springframework.stereotype.Component;

/** Vrai appel OpenFeign vers RENTAL-RESERVATION (RESERVATIONS_MOCK=false). */
@Component
@ConditionalOnProperty(name = "app.reservations.mock", havingValue = "false")
public class FeignReservationHistory implements ReservationHistory {

    private final ReservationClient client;

    public FeignReservationHistory(ReservationClient client) {
        this.client = client;
    }

    @Override
    public PageResponse<ReservationDto> findByUserId(UUID userId, int page, int size) {
        return client.findByUserId(userId, page, size);
    }
}
