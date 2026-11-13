package com.location.loyaltyservice.remote;

import com.location.loyaltyservice.common.ApiException;
import feign.FeignException;
import feign.RetryableException;
import feign.codec.DecodeException;
import java.util.Optional;
import java.util.UUID;
import java.util.function.Supplier;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.http.HttpStatus;
import org.springframework.security.oauth2.core.OAuth2AuthorizationException;
import org.springframework.stereotype.Component;

/**
 * Appels vers user-service et reservation-service, avec la traduction commune des erreurs :
 * <ul>
 *   <li>404 de l'autre service -> Optional.empty() (l'appelant décide : 422, 404...)</li>
 *   <li>connexion impossible, délai dépassé, aucune instance dans Eureka, 5xx distant,
 *       token de service impossible à obtenir -> {@link ApiException} 503</li>
 *   <li>réponse illisible, champ obligatoire absent, autre code inattendu -> {@link ApiException} 502</li>
 * </ul>
 * Utilisé par les routes HTTP et par le consommateur RabbitMQ (un 503 y est une erreur passagère : remise en file).
 */
@Component
public class RemoteServices {

    private static final Logger log = LoggerFactory.getLogger(RemoteServices.class);
    static final String USER_SERVICE = "user-service";
    static final String RESERVATION_SERVICE = "reservation-service";

    private final UserClient users;
    private final ReservationClient reservations;

    public RemoteServices(UserClient users, ReservationClient reservations) {
        this.users = users;
        this.reservations = reservations;
    }

    public Optional<RemoteUser> findUser(UUID userId) {
        Optional<RemoteUser> user = call(USER_SERVICE, () -> users.getUser(userId));
        user.ifPresent(u -> {
            if (u.id() == null || u.active() == null) {
                throw unreadable(USER_SERVICE, "id ou active absent");
            }
        });
        return user;
    }

    public Optional<RemoteReservation> findReservation(UUID reservationId) {
        Optional<RemoteReservation> reservation = call(RESERVATION_SERVICE, () -> reservations.getReservation(reservationId));
        reservation.ifPresent(r -> {
            if (r.id() == null || r.userId() == null) {
                throw unreadable(RESERVATION_SERVICE, "id ou userId absent");
            }
        });
        return reservation;
    }

    private static <T> Optional<T> call(String service, Supplier<T> request) {
        try {
            T body = request.get();
            if (body == null) {
                throw unreadable(service, "corps vide");
            }
            return Optional.of(body);
        } catch (FeignException.NotFound ex) {
            return Optional.empty();
        } catch (RetryableException ex) {
            log.warn("{} injoignable : {}", service, ex.getMessage());
            throw unavailable(service, "arrêté ou injoignable");
        } catch (DecodeException ex) {
            throw unreadable(service, ex.getMessage());
        } catch (FeignException ex) {
            if (ex.status() == 503 || ex.status() == 502 || ex.status() == 504 || ex.status() == -1) {
                log.warn("{} indisponible ({}) : {}", service, ex.status(), ex.getMessage());
                throw unavailable(service, "arrêté ou absent d'Eureka");
            }
            log.warn("Erreur {} renvoyée par {} : {}", ex.status(), service, ex.getMessage());
            throw new ApiException(HttpStatus.BAD_GATEWAY,
                    service + " a renvoyé une erreur inattendue (" + ex.status() + ")");
        } catch (OAuth2AuthorizationException ex) {
            log.warn("Token de service impossible à obtenir pour appeler {} : {}", service, ex.getMessage());
            throw unavailable(service, "token de service impossible à obtenir (Keycloak)");
        }
    }

    private static ApiException unavailable(String service, String why) {
        return new ApiException(HttpStatus.SERVICE_UNAVAILABLE, service + " est indisponible (" + why + ")");
    }

    private static ApiException unreadable(String service, String why) {
        log.warn("Réponse illisible de {} : {}", service, why);
        return new ApiException(HttpStatus.BAD_GATEWAY, "Réponse illisible de " + service);
    }
}
