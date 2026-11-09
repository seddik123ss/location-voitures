package com.location.userservice;

import static org.springframework.security.test.web.servlet.request.SecurityMockMvcRequestPostProcessors.jwt;

import org.springframework.security.core.authority.SimpleGrantedAuthority;
import org.springframework.security.test.web.servlet.request.SecurityMockMvcRequestPostProcessors.JwtRequestPostProcessor;

/** Identifiants de démo (docs/demo-ids.md) et tokens JWT simulés pour les tests. */
public final class ApiTestSupport {

    public static final String CLIENT1 = "f4d1bab3-468d-428d-b02c-b44341430bfd";
    public static final String CLIENT2 = "611f32de-ce7b-4927-b1c4-d113b6479d59";
    public static final String AGENT1 = "11c9b774-201e-4a8c-be43-6b084b52f4c7";
    public static final String ADMIN1 = "fd797399-4d3a-433d-afdd-37b0dd3437cb";
    public static final String CLIENT1_LICENSE = "edc2e0e3-709d-4caa-b617-53e4c5f128cf";
    public static final String CLIENT2_LICENSE = "3499a658-97ae-4e27-9e1c-3b2d24904828";
    public static final String CLIENT1_ADDRESS = "fa1bace4-042a-4ce2-be5f-d4c3cb4b98da";
    public static final String UNKNOWN = "00000000-0000-4000-8000-000000000000";

    /** Token d'un utilisateur : sub = id, autorités ROLE_xxx comme le ferait KeycloakRolesConverter. */
    public static JwtRequestPostProcessor as(String sub, String role) {
        return jwt().jwt(j -> j.subject(sub).claim("roles", java.util.List.of(role)))
                .authorities(new SimpleGrantedAuthority("ROLE_" + role));
    }

    public static JwtRequestPostProcessor asAdmin() {
        return as(ADMIN1, "ADMIN");
    }

    public static JwtRequestPostProcessor asAgent() {
        return as(AGENT1, "AGENT");
    }

    public static JwtRequestPostProcessor asClient1() {
        return as(CLIENT1, "CLIENT");
    }

    public static JwtRequestPostProcessor asService() {
        return as("76111062-75aa-41e8-9f16-b660185a2195", "INTERNAL");
    }

    private ApiTestSupport() {
    }
}
