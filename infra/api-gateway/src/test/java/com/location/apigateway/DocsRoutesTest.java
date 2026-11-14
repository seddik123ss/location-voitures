package com.location.apigateway;

import static org.assertj.core.api.Assertions.assertThat;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.junit.jupiter.api.Test;

class DocsRoutesTest {

    private final ObjectMapper json = new ObjectMapper();

    @Test
    void jsonContractPointsToTheGatewayWithoutInternalRoutes() throws Exception {
        String contract = """
                {"openapi":"3.0.3","servers":[{"url":"http://localhost:8104"}],
                 "paths":{"/api/payments":{"get":{}},"/internal/payments":{"get":{}},"/health":{"get":{}}}}""";

        JsonNode spec = json.readTree(DocsRoutes.forGateway(contract));

        assertThat(spec.at("/servers/0/url").asText()).isEqualTo("/");
        assertThat(spec.get("servers")).hasSize(1);
        assertThat(spec.get("paths").fieldNames()).toIterable().containsExactly("/api/payments");
    }

    @Test
    void yamlContractIsConvertedToJson() throws Exception {
        String contract = """
                openapi: 3.0.3
                servers:
                  - url: http://localhost:8088
                paths:
                  /api/vehicles:
                    get: {}
                  /internal/vehicles/{id}:
                    get: {}
                """;

        JsonNode spec = json.readTree(DocsRoutes.forGateway(contract));

        assertThat(spec.get("openapi").asText()).isEqualTo("3.0.3");
        assertThat(spec.get("paths").fieldNames()).toIterable().containsExactly("/api/vehicles");
    }

    @Test
    void unreadableContentIsReturnedUnchanged() {
        assertThat(DocsRoutes.forGateway("not: [valid")).isEqualTo("not: [valid");
    }

    @Test
    void everyServiceHasADocsRoute() {
        assertThat(DocsRoutes.SERVICES).extracting(DocsRoutes.ServiceDoc::app).containsExactlyInAnyOrder(
                "RENTAL-USER", "RENTAL-VEHICLE", "RENTAL-RESERVATION", "RENTAL-PAYMENT",
                "RENTAL-CONTRACT", "RENTAL-COMPLAINT", "RENTAL-ML", "RENTAL-LOYALTY");
    }
}
