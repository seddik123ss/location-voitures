package com.location.userservice.common;

import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.responses.ApiResponse;
import io.swagger.v3.oas.annotations.security.SecurityRequirements;
import io.swagger.v3.oas.annotations.tags.Tag;
import java.util.Map;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.RestController;

@RestController
@Tag(name = "Santé", description = "État du service")
public class HealthController {

    @GetMapping("/health")
    @SecurityRequirements
    @Operation(summary = "Vérifier que le service répond",
            description = "Route publique (sans token). Renvoie {\"status\":\"UP\"} (convention commune).")
    @ApiResponse(responseCode = "200", description = "Service démarré")
    public Map<String, String> health() {
        return Map.of("status", "UP");
    }
}
