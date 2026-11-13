package com.location.loyaltyservice;

import static org.springframework.security.test.web.servlet.request.SecurityMockMvcRequestPostProcessors.jwt;

import feign.FeignException;
import feign.Request;
import feign.Response;
import feign.RetryableException;
import feign.codec.DecodeException;
import java.nio.charset.StandardCharsets;
import java.util.List;
import java.util.Map;
import org.springframework.security.core.authority.SimpleGrantedAuthority;
import org.springframework.security.test.web.servlet.request.SecurityMockMvcRequestPostProcessors.JwtRequestPostProcessor;

/** Identifiants de démo (docs/demo-ids.md, V2__demo_data.sql), tokens JWT simulés et erreurs Feign simulées. */
public final class ApiTestSupport {

    public static final String CLIENT1 = "f4d1bab3-468d-428d-b02c-b44341430bfd";
    public static final String CLIENT2 = "611f32de-ce7b-4927-b1c4-d113b6479d59";
    public static final String AGENT1 = "11c9b774-201e-4a8c-be43-6b084b52f4c7";
    public static final String ADMIN1 = "fd797399-4d3a-433d-afdd-37b0dd3437cb";
    public static final String NEW_CLIENT = "2b6f0c4e-8d1a-4f3b-9c7e-5a0d1e2f3a4b";
    public static final String UNKNOWN = "00000000-0000-4000-8000-000000000000";

    public static final String BIENVENUE10 = "0a76460c-0f85-475e-9744-e5d95b3f32b9";
    public static final String FLASH100 = "4332ff93-5d22-4f6e-be31-13c015b1cfa4";
    public static final String R_COMPLETED = "30eeff32-a413-42fb-9845-8ede4b7f98e0";
    public static final String R_PENDING = "01cb68ad-4eec-4bdd-8971-ce35381c59d1";
    public static final String R_CONFIRMED = "33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8";

    /** Token d'un utilisateur : sub = id, autorités ROLE_xxx comme le ferait KeycloakRolesConverter. */
    public static JwtRequestPostProcessor as(String sub, String role) {
        return jwt().jwt(j -> j.subject(sub).claim("roles", List.of(role)))
                .authorities(new SimpleGrantedAuthority("ROLE_" + role));
    }

    public static JwtRequestPostProcessor asClient1() {
        return as(CLIENT1, "CLIENT");
    }

    public static JwtRequestPostProcessor asClient2() {
        return as(CLIENT2, "CLIENT");
    }

    public static JwtRequestPostProcessor asAgent() {
        return as(AGENT1, "AGENT");
    }

    public static JwtRequestPostProcessor asAdmin() {
        return as(ADMIN1, "ADMIN");
    }

    public static JwtRequestPostProcessor asService() {
        return as("9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d", "INTERNAL");
    }

    // ---------------------------------------------------------------- erreurs Feign simulées

    private static Request request() {
        return Request.create(Request.HttpMethod.GET, "http://RENTAL-X/internal", Map.of(), null,
                StandardCharsets.UTF_8, null);
    }

    /** Réponse HTTP d'erreur de l'autre service (404, 503...). */
    public static FeignException httpError(int status) {
        Response response = Response.builder().status(status).reason("error").request(request())
                .headers(Map.of()).body("{}", StandardCharsets.UTF_8).build();
        return FeignException.errorStatus("call", response);
    }

    /** Connexion refusée ou délai dépassé. */
    public static RetryableException unreachable() {
        return new RetryableException(-1, "Connection refused", Request.HttpMethod.GET, (Long) null, request());
    }

    /** Réponse illisible. */
    public static DecodeException unreadable() {
        return new DecodeException(200, "Unexpected character", request());
    }

    private ApiTestSupport() {
    }
}
