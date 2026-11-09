package com.location.userservice.common;

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

/** Documentation OpenAPI : infos, sécurité Bearer (Keycloak) et réponses d'erreur réutilisables. */
@Configuration
public class OpenApiConfig {

    @Bean
    public OpenAPI userServiceOpenApi() {
        ResolvedSchema error = ModelConverters.getInstance()
                .resolveAsResolvedSchema(new AnnotatedType(ApiError.class));

        Components components = new Components()
                .addSecuritySchemes("bearerAuth", new SecurityScheme()
                        .type(SecurityScheme.Type.HTTP).scheme("bearer").bearerFormat("JWT")
                        .description("Token Keycloak (realm location-voitures). Voir le README pour l'obtenir."))
                .addSchemas("ApiError", error.schema);
        error.referencedSchemas.forEach(components::addSchemas);

        addError(components, "BadRequest", 400, "Requête invalide (JSON illisible, paramètre ou champ obligatoire manquant)",
                "Champs obligatoires manquants", "firstname", "ne doit pas être vide");
        addError(components, "Unauthorized", 401, "Token absent ou invalide",
                "Authentification requise : token Bearer absent ou invalide", null, null);
        addError(components, "Forbidden", 403, "Rôle insuffisant ou ressource d'un autre utilisateur",
                "Accès refusé : rôle insuffisant ou ressource d'un autre utilisateur", null, null);
        addError(components, "NotFound", 404, "Objet introuvable",
                "Utilisateur introuvable : " + ApiDocs.CLIENT1_ID, null, null);
        addError(components, "Conflict", 409, "Conflit métier (doublon)",
                "Cet email est déjà utilisé", "email", "Cet email est déjà utilisé");
        addError(components, "Unprocessable", 422, "Règle métier ou format non respecté",
                "Données invalides", "email", "Format d'email invalide");
        addError(components, "BadGateway", 502, "Réponse illisible de reservation-service",
                "Réponse illisible de reservation-service", null, null);
        addError(components, "ServiceUnavailable", 503, "reservation-service arrêté ou absent d'Eureka",
                "reservation-service est indisponible (arrêté ou absent d'Eureka)", null, null);

        return new OpenAPI()
                .info(new Info()
                        .title("user-service")
                        .version("1.0.0")
                        .description("""
                                Utilisateurs, adresses et permis de conduire de la plateforme de location de voitures.
                                L'identifiant d'un utilisateur est son identifiant Keycloak ("sub").
                                Rôles : ADMIN gère les utilisateurs ; AGENT consulte et vérifie les permis ;
                                CLIENT lit et modifie son propre profil ; INTERNAL (comptes de service) pour /internal/**."""))
                .servers(List.of(
                        new Server().url("http://localhost:8101").description("direct (développement, Swagger UI)"),
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
