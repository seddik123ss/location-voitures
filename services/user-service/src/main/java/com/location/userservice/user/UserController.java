package com.location.userservice.user;

import com.location.userservice.common.ApiDocs;
import com.location.userservice.common.ApiException;
import com.location.userservice.common.PageResponse;
import com.location.userservice.reservation.ReservationDto;
import com.location.userservice.reservation.ReservationHistory;
import com.location.userservice.security.AccessGuard;
import com.location.userservice.user.dto.CreateUserRequest;
import com.location.userservice.user.dto.UpdateUserRequest;
import com.location.userservice.user.dto.UserResponse;
import com.location.userservice.user.dto.UserSummaryResponse;
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
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.PutMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;

@RestController
@RequestMapping("/api/users")
@Tag(name = "Utilisateurs", description = "Profils des utilisateurs (id = identifiant Keycloak)")
public class UserController {

    private final UserService service;
    private final ReservationHistory reservations;
    private final AccessGuard access;

    public UserController(UserService service, ReservationHistory reservations, AccessGuard access) {
        this.service = service;
        this.reservations = reservations;
        this.access = access;
    }

    @GetMapping
    @PreAuthorize("hasAnyRole('ADMIN', 'AGENT')")
    @Operation(summary = "Lister les utilisateurs (filtre et pagination)",
            description = "Rôles : ADMIN, AGENT. Tri par nom puis prénom. Le filtre `name` cherche dans le prénom, "
                    + "le nom ou \"prénom nom\", sans tenir compte de la casse.")
    @ApiResponse(responseCode = "200", description = "Page d'utilisateurs {items, page, size, total}")
    @ApiResponse(responseCode = "400", ref = ApiDocs.BAD_REQUEST)
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "403", ref = ApiDocs.FORBIDDEN)
    public PageResponse<UserSummaryResponse> list(
            @Parameter(description = "Filtrer par rôle", example = "CLIENT") @RequestParam(required = false) Role role,
            @Parameter(description = "Texte cherché dans le prénom ou le nom", example = "client")
            @RequestParam(required = false) String name,
            @Parameter(description = "Numéro de page (à partir de 0)", example = "0")
            @RequestParam(defaultValue = "0") @Min(0) int page,
            @Parameter(description = "Taille de page (1 à 100)", example = "20")
            @RequestParam(defaultValue = "20") @Min(1) @Max(100) int size) {
        return service.search(role, name, page, size);
    }

    @GetMapping("/me")
    @Operation(summary = "Mon profil",
            description = "Profil de l'utilisateur connecté (id = claim \"sub\" du token), avec adresses et permis. "
                    + "404 si le profil n'a pas encore été créé.")
    @ApiResponse(responseCode = "200", description = "Profil complet")
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "404", ref = ApiDocs.NOT_FOUND)
    public UserResponse me() {
        UUID me = access.currentUserId()
                .orElseThrow(() -> ApiException.forbidden("Le token ne contient pas d'identifiant utilisateur"));
        return service.get(me);
    }

    @GetMapping("/{id}")
    @PreAuthorize("hasAnyRole('ADMIN', 'AGENT') or @access.isSelf(#id)")
    @Operation(summary = "Consulter un utilisateur",
            description = "Rôles : ADMIN, AGENT, ou l'utilisateur lui-même. Renvoie le profil avec adresses et permis.")
    @ApiResponse(responseCode = "200", description = "Profil complet")
    @ApiResponse(responseCode = "400", ref = ApiDocs.BAD_REQUEST)
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "403", ref = ApiDocs.FORBIDDEN)
    @ApiResponse(responseCode = "404", ref = ApiDocs.NOT_FOUND)
    public UserResponse get(
            @Parameter(description = "Identifiant Keycloak de l'utilisateur", example = ApiDocs.CLIENT1_ID)
            @PathVariable UUID id) {
        return service.get(id);
    }

    @PostMapping
    @PreAuthorize("hasRole('ADMIN') or @access.isSelf(#request.id())")
    @Operation(summary = "Créer un profil utilisateur",
            description = "ADMIN : n'importe quel profil. Autre utilisateur connecté : uniquement son propre profil "
                    + "(id = son \"sub\"), actif, avec le rôle CLIENT. Réponse 201 avec l'en-tête Location.")
    @ApiResponse(responseCode = "201", description = "Profil créé (en-tête Location : /api/users/{id})")
    @ApiResponse(responseCode = "400", ref = ApiDocs.BAD_REQUEST)
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "403", ref = ApiDocs.FORBIDDEN)
    @ApiResponse(responseCode = "409", ref = ApiDocs.CONFLICT)
    @ApiResponse(responseCode = "422", ref = ApiDocs.UNPROCESSABLE)
    public ResponseEntity<UserResponse> create(@Valid @RequestBody CreateUserRequest request) {
        UserResponse created = service.create(request, access.isAdmin());
        return ResponseEntity.created(URI.create("/api/users/" + created.id())).body(created);
    }

    @PutMapping("/{id}")
    @PreAuthorize("hasRole('ADMIN') or @access.isSelf(#id)")
    @Operation(summary = "Modifier un utilisateur",
            description = "ADMIN, ou l'utilisateur lui-même. Seul un ADMIN peut changer `role` et `active` (sinon 403).")
    @ApiResponse(responseCode = "200", description = "Profil modifié")
    @ApiResponse(responseCode = "400", ref = ApiDocs.BAD_REQUEST)
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "403", ref = ApiDocs.FORBIDDEN)
    @ApiResponse(responseCode = "404", ref = ApiDocs.NOT_FOUND)
    @ApiResponse(responseCode = "409", ref = ApiDocs.CONFLICT)
    @ApiResponse(responseCode = "422", ref = ApiDocs.UNPROCESSABLE)
    public UserResponse update(
            @Parameter(description = "Identifiant Keycloak de l'utilisateur", example = ApiDocs.CLIENT1_ID)
            @PathVariable UUID id,
            @Valid @RequestBody UpdateUserRequest request) {
        return service.update(id, request, access.isAdmin());
    }

    @DeleteMapping("/{id}")
    @PreAuthorize("hasRole('ADMIN')")
    @Operation(summary = "Supprimer un utilisateur",
            description = "Rôle : ADMIN. Supprime aussi ses adresses et son permis.")
    @ApiResponse(responseCode = "204", description = "Supprimé")
    @ApiResponse(responseCode = "400", ref = ApiDocs.BAD_REQUEST)
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "403", ref = ApiDocs.FORBIDDEN)
    @ApiResponse(responseCode = "404", ref = ApiDocs.NOT_FOUND)
    public ResponseEntity<Void> delete(
            @Parameter(description = "Identifiant Keycloak de l'utilisateur", example = ApiDocs.CLIENT1_ID)
            @PathVariable UUID id) {
        service.delete(id);
        return ResponseEntity.noContent().build();
    }

    @GetMapping("/{id}/reservations")
    @PreAuthorize("hasAnyRole('ADMIN', 'AGENT') or @access.isSelf(#id)")
    @Operation(summary = "Historique des réservations d'un client (OpenFeign)",
            description = "Appelle reservation-service (nom Eureka RENTAL-RESERVATION, GET /internal/reservations?userId=) "
                    + "avec le token du compte de service. Erreurs traduites : introuvable -> 404, service arrêté ou "
                    + "absent d'Eureka -> 503, réponse illisible -> 502. Rôles : ADMIN, AGENT, ou le client lui-même. "
                    + "Tant que RESERVATIONS_MOCK=true, la réponse est simulée avec les réservations de docs/demo-ids.md.")
    @ApiResponse(responseCode = "200", description = "Page de réservations {items, page, size, total}")
    @ApiResponse(responseCode = "400", ref = ApiDocs.BAD_REQUEST)
    @ApiResponse(responseCode = "401", ref = ApiDocs.UNAUTHORIZED)
    @ApiResponse(responseCode = "403", ref = ApiDocs.FORBIDDEN)
    @ApiResponse(responseCode = "404", ref = ApiDocs.NOT_FOUND)
    @ApiResponse(responseCode = "502", ref = ApiDocs.BAD_GATEWAY)
    @ApiResponse(responseCode = "503", ref = ApiDocs.SERVICE_UNAVAILABLE)
    public PageResponse<ReservationDto> reservations(
            @Parameter(description = "Identifiant Keycloak du client", example = ApiDocs.CLIENT1_ID)
            @PathVariable UUID id,
            @Parameter(description = "Numéro de page (à partir de 0)", example = "0")
            @RequestParam(defaultValue = "0") @Min(0) int page,
            @Parameter(description = "Taille de page (1 à 100)", example = "20")
            @RequestParam(defaultValue = "20") @Min(1) @Max(100) int size) {
        service.require(id);
        return reservations.findByUserId(id, page, size);
    }
}
