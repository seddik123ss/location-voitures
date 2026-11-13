package com.location.loyaltyservice.common;

import io.swagger.v3.core.converter.AnnotatedType;
import io.swagger.v3.core.converter.ModelConverters;
import io.swagger.v3.core.converter.ResolvedSchema;
import io.swagger.v3.oas.models.Components;
import io.swagger.v3.oas.models.OpenAPI;
import io.swagger.v3.oas.models.info.Info;
import io.swagger.v3.oas.models.media.Content;
import io.swagger.v3.oas.models.media.MediaType;
import io.swagger.v3.oas.models.media.Schema;
import io.swagger.v3.oas.models.responses.ApiResponse;
import io.swagger.v3.oas.models.security.SecurityRequirement;
import io.swagger.v3.oas.models.security.SecurityScheme;
import io.swagger.v3.oas.models.servers.Server;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;

/** Documentation OpenAPI : infos, sécurité Bearer (Keycloak) et réponses d'erreur réutilisables (ref "#/components/responses/..."). */
@Configuration
public class OpenApiConfig {

    @Bean
    public OpenAPI loyaltyServiceOpenApi() {
        ResolvedSchema error = ModelConverters.getInstance()
                .resolveAsResolvedSchema(new AnnotatedType(ApiError.class));

        Components components = new Components()
                .addSecuritySchemes("bearerAuth", new SecurityScheme()
                        .type(SecurityScheme.Type.HTTP).scheme("bearer").bearerFormat("JWT")
                        .description("Token Keycloak (realm location-voitures). Voir le README pour l'obtenir."))
                .addSchemas("ApiError", error.schema);
        error.referencedSchemas.forEach(components::addSchemas);

        addError(components, "BadRequest", 400, "Requête invalide (JSON illisible, champ ou paramètre obligatoire manquant)",
                "Champs obligatoires manquants", "code", "ne doit pas être vide");
        addError(components, "Unauthorized", 401, "Token absent ou invalide",
                "Authentification requise : token Bearer absent ou invalide", null, null);
        addError(components, "Forbidden", 403, "Rôle insuffisant, niveau insuffisant ou bon d'un autre client",
                "Ce code est réservé au niveau GOLD", "code", "Ce code est réservé au niveau GOLD");
        addError(components, "NotFound", 404, "Objet introuvable",
                "Code promo introuvable : INCONNU", "code", "Code promo introuvable : INCONNU");
        addError(components, "Conflict", 409, "Conflit métier (code épuisé, déjà utilisé, doublon)",
                "Ce code promo a atteint son nombre maximal d'utilisations", "code",
                "Ce code promo a atteint son nombre maximal d'utilisations");
        addError(components, "Unprocessable", 422, "Règle métier ou format non respecté",
                "Ce code promo a expiré le 2026-09-30", "code", "Ce code promo a expiré le 2026-09-30");
        addError(components, "BadGateway", 502, "Réponse illisible de user-service ou reservation-service",
                "Réponse illisible de user-service", null, null);
        addError(components, "ServiceUnavailable", 503, "user-service ou reservation-service arrêté ou absent d'Eureka",
                "user-service est indisponible (arrêté ou absent d'Eureka)", null, null);

        return new OpenAPI()
                .info(new Info()
                        .title("loyalty-service")
                        .version("1.0.0")
                        .description("""
                                Fidélité et codes promo de la plateforme de location de voitures (Eureka : RENTAL-LOYALTY, port 8108).
                                Chaque client a un compte de points (100 points de bienvenue), gagne des points à chaque location
                                terminée, monte de niveau (BRONZE, SILVER, GOLD) et convertit ses points en bons LOY-XXXXXX.
                                L'ADMIN crée des codes promo ; reservation-service les valide et les applique (/internal).
                                Rôles : CLIENT (son compte), AGENT (lecture), ADMIN (codes promo, ajustements),
                                INTERNAL (comptes de service) pour /internal/**."""))
                .servers(List.of(
                        new Server().url("http://localhost:8108").description("direct (développement, Swagger UI)"),
                        new Server().url("http://localhost:8088").description("via la Gateway")))
                .components(components)
                .addSecurityItem(new SecurityRequirement().addList("bearerAuth"));
    }

    private static void addError(Components components, String name, int status, String description,
                                 String message, String field, String fieldMessage) {
        Map<String, Object> example = new LinkedHashMap<>();
        example.put("status", status);
        example.put("error", org.springframework.http.HttpStatus.valueOf(status).name());
        example.put("message", message);
        example.put("details", field == null ? List.of() : List.of(Map.of("field", field, "message", fieldMessage)));

        components.addResponses(name, new ApiResponse()
                .description(description)
                .content(new Content().addMediaType("application/json", new MediaType()
                        .schema(new Schema<>().$ref("#/components/schemas/ApiError"))
                        .example(example))));
    }
}
