package com.location.loyaltyservice.remote;

import java.util.UUID;
import org.springframework.cloud.openfeign.FeignClient;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;

/**
 * Client OpenFeign vers reservation-service (Aziz). Contrat : docs/internal-api.md, GET /internal/reservations/{id}.
 * Sert à retrouver le client d'une réservation terminée (ReservationCompleted ne contient pas userId).
 */
@FeignClient(name = "RENTAL-RESERVATION", configuration = RemoteFeignConfig.class)
public interface ReservationClient {

    @GetMapping("/internal/reservations/{id}")
    RemoteReservation getReservation(@PathVariable("id") UUID id);
}
