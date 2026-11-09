package com.location.userservice.user;

import com.location.userservice.common.ApiDocs;
import com.location.userservice.user.dto.InternalUserResponse;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.Parameter;
import io.swagger.v3.oas.annotations.responses.ApiResponse;
import io.swagger.v3.oas.annotations.tags.Tag;
import java.util.UUID;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

/**
 * Routes entre services. Jamais exposées par la Gateway ; réservées au rôle INTERNAL (voir SecurityConfig).
 */
@RestController
@RequestMapping("/internal/users")
@Tag(name = "Interne", description = "Routes pour les autres microservices (token de compte de service, rôle INTERNAL)")
public class InternalUserController {

    private final UserService service;

    public InternalUserController(UserService service) {
        this.service = service;
    }

    @GetMapping("/{id}")
    @Operation(summary = "Utilisateur + permis (pour reservation-service)",
            description = "Format fixé dans docs/internal-api.md : {id, firstname, lastname, email, phone, role, active, "
                    + "license: {number, category, issueDate, expiryDate, verified} ou null}. Rôle : INTERNAL.")
    @ApiResponse(responseCode = "200", description = "Utilisateur trouvé")
    @ApiResponse(responseCode = "400", ref = ApiDocs.BAD_REQUEST)
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "403", ref = ApiDocs.FORBIDDEN)
    @ApiResponse(responseCode = "404", ref = ApiDocs.NOT_FOUND)
    public InternalUserResponse get(
            @Parameter(description = "Identifiant Keycloak de l'utilisateur", example = ApiDocs.CLIENT1_ID)
            @PathVariable UUID id) {
        return service.getInternal(id);
    }
}
