package com.location.userservice.reservation;

import feign.RequestInterceptor;
import jakarta.servlet.http.HttpServletRequest;
import java.util.UUID;
import org.springframework.context.annotation.Bean;
import org.springframework.security.oauth2.client.OAuth2AuthorizeRequest;
import org.springframework.security.oauth2.client.OAuth2AuthorizedClient;
import org.springframework.security.oauth2.client.OAuth2AuthorizedClientManager;
import org.springframework.web.context.request.RequestContextHolder;
import org.springframework.web.context.request.ServletRequestAttributes;

/**
 * Configuration propre au client Feign RENTAL-RESERVATION (pas de @Configuration : ne s'applique qu'à ce client).
 * Ajoute à chaque appel :
 * <ul>
 *   <li>Authorization: Bearer &lt;token du compte de service user-service, rôle INTERNAL&gt;</li>
 *   <li>X-Correlation-Id : retransmis tel quel (ou créé s'il est absent)</li>
 * </ul>
 */
public class ReservationFeignConfig {

    static final String CORRELATION_HEADER = "X-Correlation-Id";

    @Bean
    public RequestInterceptor serviceTokenInterceptor(OAuth2AuthorizedClientManager serviceAccountClientManager) {
        return template -> {
            OAuth2AuthorizedClient client = serviceAccountClientManager.authorize(
                    OAuth2AuthorizeRequest.withClientRegistrationId("keycloak")
                            .principal("user-service")
                            .build());
            if (client != null) {
                template.header("Authorization", "Bearer " + client.getAccessToken().getTokenValue());
            }
            template.header(CORRELATION_HEADER, currentCorrelationId());
        };
    }

    private static String currentCorrelationId() {
        if (RequestContextHolder.getRequestAttributes() instanceof ServletRequestAttributes attrs) {
            HttpServletRequest request = attrs.getRequest();
            String id = request.getHeader(CORRELATION_HEADER);
            if (id != null && !id.isBlank()) {
                return id;
            }
        }
        return UUID.randomUUID().toString();
    }
}
