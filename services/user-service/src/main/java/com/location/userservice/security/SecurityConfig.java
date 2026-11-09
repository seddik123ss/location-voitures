package com.location.userservice.security;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.location.userservice.common.ApiError;
import jakarta.servlet.http.HttpServletResponse;
import java.io.IOException;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.http.HttpStatus;
import org.springframework.http.MediaType;
import org.springframework.security.config.annotation.method.configuration.EnableMethodSecurity;
import org.springframework.security.config.annotation.web.builders.HttpSecurity;
import org.springframework.security.config.annotation.web.configurers.AbstractHttpConfigurer;
import org.springframework.security.config.http.SessionCreationPolicy;
import org.springframework.security.oauth2.server.resource.authentication.JwtAuthenticationConverter;
import org.springframework.security.web.SecurityFilterChain;
import org.springframework.security.web.access.AccessDeniedHandler;
import org.springframework.security.web.AuthenticationEntryPoint;

/**
 * Sécurité Keycloak (resource server JWT).
 * <ul>
 *   <li>Publiques : /health, Swagger.</li>
 *   <li>/internal/** : rôle INTERNAL (comptes de service des autres microservices).</li>
 *   <li>Le reste : token obligatoire ; les règles fines (ADMIN, propriétaire...) sont sur les contrôleurs
 *       avec @PreAuthorize.</li>
 * </ul>
 */
@Configuration
@EnableMethodSecurity
public class SecurityConfig {

    @Bean
    public SecurityFilterChain securityFilterChain(HttpSecurity http, ObjectMapper objectMapper) throws Exception {
        AuthenticationEntryPoint unauthorized = (request, response, ex) -> write(response, objectMapper,
                HttpStatus.UNAUTHORIZED, "Authentification requise : token Bearer absent ou invalide");
        AccessDeniedHandler forbidden = (request, response, ex) -> write(response, objectMapper,
                HttpStatus.FORBIDDEN, "Accès refusé : rôle insuffisant");

        http
                .csrf(AbstractHttpConfigurer::disable)
                .sessionManagement(s -> s.sessionCreationPolicy(SessionCreationPolicy.STATELESS))
                .authorizeHttpRequests(auth -> auth
                        .requestMatchers("/health", "/error",
                                "/swagger-ui.html", "/swagger-ui/**", "/v3/api-docs", "/v3/api-docs/**",
                                "/v3/api-docs.yaml").permitAll()
                        .requestMatchers("/internal/**").hasRole("INTERNAL")
                        .anyRequest().authenticated())
                .oauth2ResourceServer(oauth -> oauth
                        .jwt(jwt -> jwt.jwtAuthenticationConverter(jwtAuthenticationConverter()))
                        .authenticationEntryPoint(unauthorized)
                        .accessDeniedHandler(forbidden))
                .exceptionHandling(e -> e.authenticationEntryPoint(unauthorized).accessDeniedHandler(forbidden));
        return http.build();
    }

    @Bean
    public JwtAuthenticationConverter jwtAuthenticationConverter() {
        JwtAuthenticationConverter converter = new JwtAuthenticationConverter();
        converter.setJwtGrantedAuthoritiesConverter(new KeycloakRolesConverter());
        // le "nom" de l'utilisateur connecté = claim "sub" = id de l'utilisateur dans user_db
        converter.setPrincipalClaimName("sub");
        return converter;
    }

    private static void write(HttpServletResponse response, ObjectMapper objectMapper,
                              HttpStatus status, String message) throws IOException {
        response.setStatus(status.value());
        response.setContentType(MediaType.APPLICATION_JSON_VALUE);
        response.setCharacterEncoding("UTF-8");
        objectMapper.writeValue(response.getOutputStream(), ApiError.of(status, message));
    }
}
