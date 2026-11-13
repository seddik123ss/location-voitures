package com.location.loyaltyservice.remote;

import com.location.loyaltyservice.common.CorrelationId;
import feign.RequestInterceptor;
import org.springframework.context.annotation.Bean;
import org.springframework.security.oauth2.client.OAuth2AuthorizeRequest;
import org.springframework.security.oauth2.client.OAuth2AuthorizedClient;
import org.springframework.security.oauth2.client.OAuth2AuthorizedClientManager;

/**
 * Configuration des clients Feign RENTAL-USER et RENTAL-RESERVATION (pas de @Configuration : seulement pour eux).
 * Ajoute à chaque appel :
 * <ul>
 *   <li>Authorization: Bearer &lt;token du compte de service loyalty-service, rôle INTERNAL&gt;</li>
 *   <li>X-Correlation-Id : celui de la requête ou du message en cours, sinon un nouveau</li>
 * </ul>
 */
public class RemoteFeignConfig {

    @Bean
    public RequestInterceptor serviceTokenInterceptor(OAuth2AuthorizedClientManager serviceAccountClientManager) {
        return template -> {
            OAuth2AuthorizedClient client = serviceAccountClientManager.authorize(
                    OAuth2AuthorizeRequest.withClientRegistrationId("keycloak")
                            .principal("loyalty-service")
                            .build());
            if (client != null) {
                template.header("Authorization", "Bearer " + client.getAccessToken().getTokenValue());
            }
            template.header(CorrelationId.HEADER, CorrelationId.current());
        };
    }
}
