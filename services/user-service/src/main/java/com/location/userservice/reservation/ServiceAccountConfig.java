package com.location.userservice.reservation;

import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.security.oauth2.client.AuthorizedClientServiceOAuth2AuthorizedClientManager;
import org.springframework.security.oauth2.client.OAuth2AuthorizedClientManager;
import org.springframework.security.oauth2.client.OAuth2AuthorizedClientProviderBuilder;
import org.springframework.security.oauth2.client.OAuth2AuthorizedClientService;
import org.springframework.security.oauth2.client.registration.ClientRegistrationRepository;

/**
 * Obtient (et garde en cache jusqu'à expiration) le token du compte de service "user-service"
 * (client credentials, rôle INTERNAL) pour appeler les routes /internal des autres services.
 */
@Configuration
public class ServiceAccountConfig {

    @Bean
    public OAuth2AuthorizedClientManager serviceAccountClientManager(ClientRegistrationRepository registrations,
                                                                     OAuth2AuthorizedClientService clients) {
        var manager = new AuthorizedClientServiceOAuth2AuthorizedClientManager(registrations, clients);
        manager.setAuthorizedClientProvider(OAuth2AuthorizedClientProviderBuilder.builder()
                .clientCredentials()
                .build());
        return manager;
    }
}
