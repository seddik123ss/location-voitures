package com.location.userservice.address;

import com.location.userservice.address.dto.AddressRequest;
import com.location.userservice.address.dto.AddressResponse;
import com.location.userservice.common.ApiDocs;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.Parameter;
import io.swagger.v3.oas.annotations.responses.ApiResponse;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.validation.Valid;
import java.net.URI;
import java.util.List;
import java.util.UUID;
import org.springframework.http.ResponseEntity;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.web.bind.annotation.DeleteMapping;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.PutMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

/** Adresses d'un utilisateur, sous /api/users/{userId}/addresses (chemin couvert par la route Gateway /api/users/**). */
@RestController
@RequestMapping("/api/users/{userId}/addresses")
@Tag(name = "Adresses", description = "Adresses d'un utilisateur")
public class AddressController {

    private static final String USER_DESC = "Identifiant Keycloak de l'utilisateur";
    private static final String ADDRESS_EXAMPLE = "fa1bace4-042a-4ce2-be5f-d4c3cb4b98da";

    private final AddressService service;

    public AddressController(AddressService service) {
        this.service = service;
    }

    @GetMapping
    @PreAuthorize("hasAnyRole('ADMIN', 'AGENT') or @access.isSelf(#userId)")
    @Operation(summary = "Lister les adresses d'un utilisateur", description = "Rôles : ADMIN, AGENT, ou l'utilisateur lui-même.")
    @ApiResponse(responseCode = "200", description = "Adresses de l'utilisateur")
    @ApiResponse(responseCode = "400", ref = ApiDocs.BAD_REQUEST)
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "403", ref = ApiDocs.FORBIDDEN)
    @ApiResponse(responseCode = "404", ref = ApiDocs.NOT_FOUND)
    public List<AddressResponse> list(
            @Parameter(description = USER_DESC, example = ApiDocs.CLIENT1_ID) @PathVariable UUID userId) {
        return service.list(userId);
    }

    @GetMapping("/{addressId}")
    @PreAuthorize("hasAnyRole('ADMIN', 'AGENT') or @access.isSelf(#userId)")
    @Operation(summary = "Consulter une adresse", description = "Rôles : ADMIN, AGENT, ou l'utilisateur lui-même.")
    @ApiResponse(responseCode = "200", description = "Adresse")
    @ApiResponse(responseCode = "400", ref = ApiDocs.BAD_REQUEST)
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "403", ref = ApiDocs.FORBIDDEN)
    @ApiResponse(responseCode = "404", ref = ApiDocs.NOT_FOUND)
    public AddressResponse get(
            @Parameter(description = USER_DESC, example = ApiDocs.CLIENT1_ID) @PathVariable UUID userId,
            @Parameter(description = "Identifiant de l'adresse", example = ADDRESS_EXAMPLE) @PathVariable UUID addressId) {
        return service.get(userId, addressId);
    }

    @PostMapping
    @PreAuthorize("hasRole('ADMIN') or @access.isSelf(#userId)")
    @Operation(summary = "Ajouter une adresse", description = "ADMIN, ou l'utilisateur lui-même. Réponse 201 avec Location.")
    @ApiResponse(responseCode = "201", description = "Adresse créée")
    @ApiResponse(responseCode = "400", ref = ApiDocs.BAD_REQUEST)
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "403", ref = ApiDocs.FORBIDDEN)
    @ApiResponse(responseCode = "404", ref = ApiDocs.NOT_FOUND)
    @ApiResponse(responseCode = "422", ref = ApiDocs.UNPROCESSABLE)
    public ResponseEntity<AddressResponse> create(
            @Parameter(description = USER_DESC, example = ApiDocs.CLIENT1_ID) @PathVariable UUID userId,
            @Valid @RequestBody AddressRequest request) {
        AddressResponse created = service.create(userId, request);
        return ResponseEntity.created(URI.create("/api/users/" + userId + "/addresses/" + created.id())).body(created);
    }

    @PutMapping("/{addressId}")
    @PreAuthorize("hasRole('ADMIN') or @access.isSelf(#userId)")
    @Operation(summary = "Modifier une adresse", description = "ADMIN, ou l'utilisateur lui-même.")
    @ApiResponse(responseCode = "200", description = "Adresse modifiée")
    @ApiResponse(responseCode = "400", ref = ApiDocs.BAD_REQUEST)
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "403", ref = ApiDocs.FORBIDDEN)
    @ApiResponse(responseCode = "404", ref = ApiDocs.NOT_FOUND)
    @ApiResponse(responseCode = "422", ref = ApiDocs.UNPROCESSABLE)
    public AddressResponse update(
            @Parameter(description = USER_DESC, example = ApiDocs.CLIENT1_ID) @PathVariable UUID userId,
            @Parameter(description = "Identifiant de l'adresse", example = ADDRESS_EXAMPLE) @PathVariable UUID addressId,
            @Valid @RequestBody AddressRequest request) {
        return service.update(userId, addressId, request);
    }

    @DeleteMapping("/{addressId}")
    @PreAuthorize("hasRole('ADMIN') or @access.isSelf(#userId)")
    @Operation(summary = "Supprimer une adresse", description = "ADMIN, ou l'utilisateur lui-même.")
    @ApiResponse(responseCode = "204", description = "Supprimée")
    @ApiResponse(responseCode = "400", ref = ApiDocs.BAD_REQUEST)
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "403", ref = ApiDocs.FORBIDDEN)
    @ApiResponse(responseCode = "404", ref = ApiDocs.NOT_FOUND)
    public ResponseEntity<Void> delete(
            @Parameter(description = USER_DESC, example = ApiDocs.CLIENT1_ID) @PathVariable UUID userId,
            @Parameter(description = "Identifiant de l'adresse", example = ADDRESS_EXAMPLE) @PathVariable UUID addressId) {
        service.delete(userId, addressId);
        return ResponseEntity.noContent().build();
    }
}
