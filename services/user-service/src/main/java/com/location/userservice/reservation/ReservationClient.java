package com.location.userservice.reservation;

import com.location.userservice.common.PageResponse;
import java.util.UUID;
import org.springframework.cloud.openfeign.FeignClient;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.RequestParam;

/**
 * Client OpenFeign vers reservation-service (Aziz, Laravel).
 * name = nom Eureka : l'adresse réelle est trouvée dans Eureka, jamais d'URL en dur.
 * Les erreurs Feign sont traduites par {@link FeignErrorHandler} (404 / 503 / 502).
 */
@FeignClient(name = "RENTAL-RESERVATION", configuration = ReservationFeignConfig.class)
public interface ReservationClient {

    @GetMapping("/internal/reservations")
    PageResponse<ReservationDto> findByUserId(@RequestParam("userId") UUID userId,
                                              @RequestParam("page") int page,
                                              @RequestParam("size") int size);
}
