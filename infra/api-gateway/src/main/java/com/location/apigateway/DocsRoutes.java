package com.location.apigateway;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.node.ObjectNode;
import com.fasterxml.jackson.dataformat.yaml.YAMLFactory;
import java.util.Iterator;
import java.util.List;
import org.springframework.cloud.gateway.route.RouteLocator;
import org.springframework.cloud.gateway.route.builder.RouteLocatorBuilder;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.http.MediaType;
import reactor.core.publisher.Mono;

/**
 * Swagger UI centralisé (http://localhost:8088/swagger-ui.html) : GET /docs/{service} renvoie le contrat OpenAPI
 * du service, lu chez lui via Eureka (lb://RENTAL-...), et la liste déroulante de la page les propose tous
 * (springdoc.swagger-ui.urls dans application.yml).
 *
 * Chaque contrat est adapté à la Gateway avant d'être renvoyé (JSON, même si le service publie du YAML) :
 * - servers = la Gateway elle-même ("/") : "Try it out" passe par la Gateway, avec le token (bouton Authorize) ;
 * - les chemins non exposés par la Gateway (/internal/**, /health) sont retirés.
 */
@Configuration
public class DocsRoutes {

    /** name = segment de /docs/{name} ; app = nom Eureka ; path = adresse du contrat chez le service. */
    record ServiceDoc(String name, String app, String path) {}

    static final List<ServiceDoc> SERVICES = List.of(
            new ServiceDoc("user", "RENTAL-USER", "/v3/api-docs"),
            new ServiceDoc("vehicle", "RENTAL-VEHICLE", "/openapi.yaml"),
            new ServiceDoc("reservation", "RENTAL-RESERVATION", "/docs"),
            new ServiceDoc("payment", "RENTAL-PAYMENT", "/api/payments/openapi.json"),
            new ServiceDoc("contract", "RENTAL-CONTRACT", "/swagger/v1/swagger.json"),
            new ServiceDoc("complaint", "RENTAL-COMPLAINT", "/api/doc.json"),
            new ServiceDoc("ml", "RENTAL-ML", "/openapi.json"),
            new ServiceDoc("loyalty", "RENTAL-LOYALTY", "/v3/api-docs"));

    // YAML est un sur-ensemble de JSON : ce lecteur accepte les deux formats.
    private static final ObjectMapper YAML = new ObjectMapper(new YAMLFactory());
    private static final ObjectMapper JSON = new ObjectMapper();

    @Bean
    public RouteLocator docsRouteLocator(RouteLocatorBuilder builder) {
        RouteLocatorBuilder.Builder routes = builder.routes();
        for (ServiceDoc doc : SERVICES) {
            routes.route("docs-" + doc.name(), r -> r.path("/docs/" + doc.name())
                    .filters(f -> f.setPath(doc.path())
                            .modifyResponseBody(String.class, String.class, MediaType.APPLICATION_JSON_VALUE,
                                    (exchange, body) -> Mono.justOrEmpty(body).map(DocsRoutes::forGateway)))
                    .uri("lb://" + doc.app()));
        }
        return routes.build();
    }

    /** Contrat du service -> contrat vu depuis la Gateway (JSON). Contenu illisible : renvoyé tel quel. */
    static String forGateway(String contract) {
        try {
            JsonNode root = YAML.readTree(contract);
            if (!(root instanceof ObjectNode spec)) {
                return contract;
            }
            spec.putArray("servers").addObject()
                    .put("url", "/")
                    .put("description", "API Gateway (token Keycloak : bouton Authorize)");
            if (spec.get("paths") instanceof ObjectNode paths) {
                for (Iterator<String> names = paths.fieldNames(); names.hasNext(); ) {
                    String name = names.next();
                    if (name.startsWith("/internal") || name.equals("/health")) {
                        names.remove();
                    }
                }
            }
            return JSON.writeValueAsString(spec);
        } catch (Exception e) {
            return contract;
        }
    }
}
