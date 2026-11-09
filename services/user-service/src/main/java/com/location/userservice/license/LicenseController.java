package com.location.userservice.license;

import com.location.userservice.common.ApiDocs;
import com.location.userservice.common.PageResponse;
import com.location.userservice.license.dto.CreateLicenseRequest;
import com.location.userservice.license.dto.LicenseResponse;
import com.location.userservice.license.dto.UpdateLicenseRequest;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.Parameter;
import io.swagger.v3.oas.annotations.responses.ApiResponse;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.validation.Valid;
import jakarta.validation.constraints.Max;
import jakarta.validation.constraints.Min;
import java.net.URI;
import java.util.UUID;
import org.springframework.http.ResponseEntity;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.web.bind.annotation.DeleteMapping;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PatchMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.PutMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;

@RestController
@RequestMapping("/api/licenses")
@Tag(name = "Permis", description = "Permis de conduire (un par utilisateur)")
public class LicenseController {

    private static final String LICENSE_EXAMPLE = "edc2e0e3-709d-4caa-b617-53e4c5f128cf";

    private final LicenseService service;

    public LicenseController(LicenseService service) {
        this.service = service;
    }

    @GetMapping
    @PreAuthorize("hasAnyRole('ADMIN', 'AGENT')")
    @Operation(summary = "Lister les permis", description = "Rôles : ADMIN, AGENT. Filtre facultatif par utilisateur. "
            + "Tri par date d'expiration croissante.")
    @ApiResponse(responseCode = "200", description = "Page de permis {items, page, size, total}")
    @ApiResponse(responseCode = "400", ref = ApiDocs.BAD_REQUEST)
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "403", ref = ApiDocs.FORBIDDEN)
    public PageResponse<LicenseResponse> list(
            @Parameter(description = "Filtrer par utilisateur", example = ApiDocs.CLIENT1_ID)
            @RequestParam(required = false) UUID userId,
            @Parameter(description = "Numéro de page (à partir de 0)", example = "0")
            @RequestParam(defaultValue = "0") @Min(0) int page,
            @Parameter(description = "Taille de page (1 à 100)", example = "20")
            @RequestParam(defaultValue = "20") @Min(1) @Max(100) int size) {
        return service.list(userId, page, size);
    }

    @GetMapping("/{id}")
    @PreAuthorize("@access.canReadLicense(#id)")
    @Operation(summary = "Consulter un permis", description = "Rôles : ADMIN, AGENT, ou le propriétaire du permis.")
    @ApiResponse(responseCode = "200", description = "Permis")
    @ApiResponse(responseCode = "400", ref = ApiDocs.BAD_REQUEST)
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "403", ref = ApiDocs.FORBIDDEN)
    @ApiResponse(responseCode = "404", ref = ApiDocs.NOT_FOUND)
    public LicenseResponse get(
            @Parameter(description = "Identifiant du permis", example = LICENSE_EXAMPLE) @PathVariable UUID id) {
        return service.get(id);
    }

    @PostMapping
    @PreAuthorize("hasRole('ADMIN') or @access.isSelf(#request.userId())")
    @Operation(summary = "Ajouter un permis",
            description = "ADMIN, ou l'utilisateur pour lui-même. Règles : un seul permis par utilisateur (409), "
                    + "numéro unique (409), permis déjà expiré (422), délivrance dans le futur ou expiration avant "
                    + "délivrance (422). Le permis créé n'est pas vérifié. Réponse 201 avec Location.")
    @ApiResponse(responseCode = "201", description = "Permis créé (en-tête Location : /api/licenses/{id})")
    @ApiResponse(responseCode = "400", ref = ApiDocs.BAD_REQUEST)
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "403", ref = ApiDocs.FORBIDDEN)
    @ApiResponse(responseCode = "404", ref = ApiDocs.NOT_FOUND)
    @ApiResponse(responseCode = "409", ref = ApiDocs.CONFLICT)
    @ApiResponse(responseCode = "422", ref = ApiDocs.UNPROCESSABLE)
    public ResponseEntity<LicenseResponse> create(@Valid @RequestBody CreateLicenseRequest request) {
        LicenseResponse created = service.create(request);
        return ResponseEntity.created(URI.create("/api/licenses/" + created.id())).body(created);
    }

    @PutMapping("/{id}")
    @PreAuthorize("@access.canManageLicense(#id)")
    @Operation(summary = "Modifier un permis",
            description = "ADMIN, ou le propriétaire. Une modification annule la vérification (verified = false).")
    @ApiResponse(responseCode = "200", description = "Permis modifié")
    @ApiResponse(responseCode = "400", ref = ApiDocs.BAD_REQUEST)
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "403", ref = ApiDocs.FORBIDDEN)
    @ApiResponse(responseCode = "404", ref = ApiDocs.NOT_FOUND)
    @ApiResponse(responseCode = "409", ref = ApiDocs.CONFLICT)
    @ApiResponse(responseCode = "422", ref = ApiDocs.UNPROCESSABLE)
    public LicenseResponse update(
            @Parameter(description = "Identifiant du permis", example = LICENSE_EXAMPLE) @PathVariable UUID id,
            @Valid @RequestBody UpdateLicenseRequest request) {
        return service.update(id, request);
    }

    @PatchMapping("/{id}/verify")
    @PreAuthorize("hasAnyRole('ADMIN', 'AGENT')")
    @Operation(summary = "Marquer un permis comme vérifié",
            description = "Rôles : ADMIN, AGENT (contrôle du permis physique en agence). 422 si le permis est expiré.")
    @ApiResponse(responseCode = "200", description = "Permis vérifié")
    @ApiResponse(responseCode = "400", ref = ApiDocs.BAD_REQUEST)
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "403", ref = ApiDocs.FORBIDDEN)
    @ApiResponse(responseCode = "404", ref = ApiDocs.NOT_FOUND)
    @ApiResponse(responseCode = "422", ref = ApiDocs.UNPROCESSABLE)
    public LicenseResponse verify(
            @Parameter(description = "Identifiant du permis", example = LICENSE_EXAMPLE) @PathVariable UUID id) {
        return service.verify(id);
    }

    @DeleteMapping("/{id}")
    @PreAuthorize("@access.canManageLicense(#id)")
    @Operation(summary = "Supprimer un permis", description = "ADMIN, ou le propriétaire.")
    @ApiResponse(responseCode = "204", description = "Supprimé")
    @ApiResponse(responseCode = "400", ref = ApiDocs.BAD_REQUEST)
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "403", ref = ApiDocs.FORBIDDEN)
    @ApiResponse(responseCode = "404", ref = ApiDocs.NOT_FOUND)
    public ResponseEntity<Void> delete(
            @Parameter(description = "Identifiant du permis", example = LICENSE_EXAMPLE) @PathVariable UUID id) {
        service.delete(id);
        return ResponseEntity.noContent().build();
    }
}
