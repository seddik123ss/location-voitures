package com.location.userservice.reservation;

import com.location.userservice.common.ApiError;
import feign.FeignException;
import feign.RetryableException;
import feign.codec.DecodeException;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.core.Ordered;
import org.springframework.core.annotation.Order;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.ExceptionHandler;
import org.springframework.web.bind.annotation.RestControllerAdvice;

/**
 * Démonstration du workshop OpenFeign : traduction des erreurs d'appel vers reservation-service.
 * <ul>
 *   <li>FeignException.NotFound (404 renvoyé par l'autre service) -> 404</li>
 *   <li>RetryableException (connexion impossible, délai dépassé) -> 503</li>
 *   <li>FeignException.ServiceUnavailable (aucune instance dans Eureka, ou 503 distant) -> 503</li>
 *   <li>DecodeException (réponse illisible) -> 502</li>
 *   <li>toute autre erreur distante -> 502</li>
 * </ul>
 * Priorité la plus haute : passe avant le gestionnaire général.
 */
@RestControllerAdvice
@Order(Ordered.HIGHEST_PRECEDENCE)
public class FeignErrorHandler {

    private static final Logger log = LoggerFactory.getLogger(FeignErrorHandler.class);
    private static final String REMOTE = "reservation-service";

    @ExceptionHandler(FeignException.NotFound.class)
    public ResponseEntity<ApiError> notFound(FeignException.NotFound ex) {
        log.warn("{} : objet introuvable ({})", REMOTE, ex.getMessage());
        return build(HttpStatus.NOT_FOUND, "Introuvable dans " + REMOTE);
    }

    @ExceptionHandler(RetryableException.class)
    public ResponseEntity<ApiError> unreachable(RetryableException ex) {
        log.warn("{} injoignable : {}", REMOTE, ex.getMessage());
        return build(HttpStatus.SERVICE_UNAVAILABLE, REMOTE + " est indisponible (arrêté ou injoignable)");
    }

    @ExceptionHandler(FeignException.ServiceUnavailable.class)
    public ResponseEntity<ApiError> unavailable(FeignException.ServiceUnavailable ex) {
        log.warn("{} indisponible : {}", REMOTE, ex.getMessage());
        return build(HttpStatus.SERVICE_UNAVAILABLE, REMOTE + " est indisponible (arrêté ou absent d'Eureka)");
    }

    @ExceptionHandler(DecodeException.class)
    public ResponseEntity<ApiError> unreadable(DecodeException ex) {
        log.warn("Réponse illisible de {} : {}", REMOTE, ex.getMessage());
        return build(HttpStatus.BAD_GATEWAY, "Réponse illisible de " + REMOTE);
    }

    @ExceptionHandler(FeignException.class)
    public ResponseEntity<ApiError> otherRemoteError(FeignException ex) {
        log.warn("Erreur {} renvoyée par {} : {}", ex.status(), REMOTE, ex.getMessage());
        return build(HttpStatus.BAD_GATEWAY, REMOTE + " a renvoyé une erreur inattendue (" + ex.status() + ")");
    }

    private static ResponseEntity<ApiError> build(HttpStatus status, String message) {
        return ResponseEntity.status(status).body(ApiError.of(status, message));
    }
}
