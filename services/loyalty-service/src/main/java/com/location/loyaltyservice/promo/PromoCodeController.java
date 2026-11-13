package com.location.loyaltyservice.promo;

import com.location.loyaltyservice.common.PageResponse;
import com.location.loyaltyservice.promo.dto.CheckRequest;
import com.location.loyaltyservice.promo.dto.DiscountResponse;
import com.location.loyaltyservice.promo.dto.PromoCodeRequest;
import com.location.loyaltyservice.promo.dto.PromoCodeResponse;
import com.location.loyaltyservice.promo.dto.PromoCodeUpdateRequest;
import com.location.loyaltyservice.security.AccessGuard;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.responses.ApiResponse;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.validation.Valid;
import jakarta.validation.constraints.Max;
import jakarta.validation.constraints.Min;
import java.net.URI;
import java.time.Clock;
import java.time.LocalDate;
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
@RequestMapping("/api/promo-codes")
@Tag(name = "Codes promo", description = "Codes promo de l'ADMIN (lecture AGENT) et aperçu pour le client")
public class PromoCodeController {

    private final PromoCodeService promoCodes;
    private final AccessGuard access;
    private final Clock clock;

    public PromoCodeController(PromoCodeService promoCodes, AccessGuard access, Clock clock) {
        this.promoCodes = promoCodes;
        this.access = access;
        this.clock = clock;
    }

    @GetMapping
    @PreAuthorize("hasAnyRole('AGENT','ADMIN')")
    @Operation(summary = "Lister les codes promo", description = "Sans les bons personnels LOY-... ; filtre facultatif active. AGENT, ADMIN.")
    @ApiResponse(responseCode = "200", description = "Page de codes")
    @ApiResponse(responseCode = "400", ref = "#/components/responses/BadRequest")
    @ApiResponse(responseCode = "401", ref = "#/components/responses/Unauthorized")
    @ApiResponse(responseCode = "403", ref = "#/components/responses/Forbidden")
    public PageResponse<PromoCodeResponse> list(
            @RequestParam(required = false) Boolean active,
            @RequestParam(defaultValue = "0") @Min(0) int page,
            @RequestParam(defaultValue = "20") @Min(1) @Max(100) int size) {
        LocalDate today = LocalDate.now(clock);
        return PageResponse.from(promoCodes.list(active, page, size), p -> PromoCodeResponse.from(p, today));
    }

    @GetMapping("/{id}")
    @PreAuthorize("hasAnyRole('AGENT','ADMIN')")
    @Operation(summary = "Détail d'un code", description = "Avec son compteur d'utilisations. AGENT, ADMIN.")
    @ApiResponse(responseCode = "200", description = "Code promo")
    @ApiResponse(responseCode = "401", ref = "#/components/responses/Unauthorized")
    @ApiResponse(responseCode = "403", ref = "#/components/responses/Forbidden")
    @ApiResponse(responseCode = "404", ref = "#/components/responses/NotFound")
    public PromoCodeResponse get(@PathVariable UUID id) {
        return PromoCodeResponse.from(promoCodes.get(id), LocalDate.now(clock));
    }

    @PostMapping
    @PreAuthorize("hasRole('ADMIN')")
    @Operation(summary = "Créer un code promo",
            description = "Code déjà existant : 409. validUntil <= validFrom, pourcentage hors 1 à 50, valeur <= 0 : 422. ADMIN.")
    @ApiResponse(responseCode = "201", description = "Code créé (en-tête Location)")
    @ApiResponse(responseCode = "400", ref = "#/components/responses/BadRequest")
    @ApiResponse(responseCode = "401", ref = "#/components/responses/Unauthorized")
    @ApiResponse(responseCode = "403", ref = "#/components/responses/Forbidden")
    @ApiResponse(responseCode = "409", ref = "#/components/responses/Conflict")
    @ApiResponse(responseCode = "422", ref = "#/components/responses/Unprocessable")
    public ResponseEntity<PromoCodeResponse> create(@Valid @RequestBody PromoCodeRequest request) {
        PromoCode created = promoCodes.create(request);
        return ResponseEntity.created(URI.create("/api/promo-codes/" + created.getId()))
                .body(PromoCodeResponse.from(created, LocalDate.now(clock)));
    }

    @PutMapping("/{id}")
    @PreAuthorize("hasRole('ADMIN')")
    @Operation(summary = "Modifier un code promo", description = "active=false pour le désactiver. ADMIN.")
    @ApiResponse(responseCode = "200", description = "Code modifié")
    @ApiResponse(responseCode = "400", ref = "#/components/responses/BadRequest")
    @ApiResponse(responseCode = "401", ref = "#/components/responses/Unauthorized")
    @ApiResponse(responseCode = "403", ref = "#/components/responses/Forbidden")
    @ApiResponse(responseCode = "404", ref = "#/components/responses/NotFound")
    @ApiResponse(responseCode = "422", ref = "#/components/responses/Unprocessable")
    public PromoCodeResponse update(@PathVariable UUID id, @Valid @RequestBody PromoCodeUpdateRequest request) {
        return PromoCodeResponse.from(promoCodes.update(id, request), LocalDate.now(clock));
    }

    @DeleteMapping("/{id}")
    @PreAuthorize("hasRole('ADMIN')")
    @Operation(summary = "Supprimer un code promo", description = "204 si jamais utilisé ; sinon 409 (le désactiver). ADMIN.")
    @ApiResponse(responseCode = "204", description = "Code supprimé")
    @ApiResponse(responseCode = "401", ref = "#/components/responses/Unauthorized")
    @ApiResponse(responseCode = "403", ref = "#/components/responses/Forbidden")
    @ApiResponse(responseCode = "404", ref = "#/components/responses/NotFound")
    @ApiResponse(responseCode = "409", ref = "#/components/responses/Conflict")
    public ResponseEntity<Void> delete(@PathVariable UUID id) {
        promoCodes.delete(id);
        return ResponseEntity.noContent().build();
    }

    @PostMapping("/check")
    @PreAuthorize("hasRole('CLIENT')")
    @Operation(summary = "Aperçu d'un code (client connecté)",
            description = "Calcule {code, discount, amountAfter} sans rien consommer, avec les mêmes contrôles que la "
                    + "réservation (404, 422, 403, 409). CLIENT.")
    @ApiResponse(responseCode = "200", description = "Réduction calculée")
    @ApiResponse(responseCode = "400", ref = "#/components/responses/BadRequest")
    @ApiResponse(responseCode = "401", ref = "#/components/responses/Unauthorized")
    @ApiResponse(responseCode = "403", ref = "#/components/responses/Forbidden")
    @ApiResponse(responseCode = "404", ref = "#/components/responses/NotFound")
    @ApiResponse(responseCode = "409", ref = "#/components/responses/Conflict")
    @ApiResponse(responseCode = "422", ref = "#/components/responses/Unprocessable")
    public DiscountResponse check(@Valid @RequestBody CheckRequest request) {
        return DiscountResponse.from(promoCodes.check(request.code(), access.requireUserId(), request.amount()));
    }
}
