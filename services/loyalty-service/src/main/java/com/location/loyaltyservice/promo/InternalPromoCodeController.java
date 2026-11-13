package com.location.loyaltyservice.promo;

import com.location.loyaltyservice.promo.dto.ApplyRequest;
import com.location.loyaltyservice.promo.dto.DiscountResponse;
import com.location.loyaltyservice.promo.dto.UsageResponse;
import com.location.loyaltyservice.promo.dto.ValidateRequest;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.responses.ApiResponse;
import io.swagger.v3.oas.annotations.tags.Tag;
import jakarta.validation.Valid;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

/** Routes appelées par reservation-service : rôle INTERNAL (SecurityConfig), jamais exposées par la Gateway. */
@RestController
@RequestMapping("/internal/promo-codes")
@Tag(name = "Interne", description = "Routes entre services (rôle INTERNAL, hors Gateway)")
public class InternalPromoCodeController {

    private final PromoCodeService promoCodes;

    public InternalPromoCodeController(PromoCodeService promoCodes) {
        this.promoCodes = promoCodes;
    }

    @PostMapping("/validate")
    @Operation(summary = "[Interne] Valider un code pour une réservation",
            description = "Ne consomme rien. Vérifie aussi que l'utilisateur est actif dans user-service. INTERNAL.")
    @ApiResponse(responseCode = "200", description = "{code, amountBefore, discount, amountAfter}")
    @ApiResponse(responseCode = "400", ref = "#/components/responses/BadRequest")
    @ApiResponse(responseCode = "403", ref = "#/components/responses/Forbidden")
    @ApiResponse(responseCode = "404", ref = "#/components/responses/NotFound")
    @ApiResponse(responseCode = "409", ref = "#/components/responses/Conflict")
    @ApiResponse(responseCode = "422", ref = "#/components/responses/Unprocessable")
    @ApiResponse(responseCode = "502", ref = "#/components/responses/BadGateway")
    @ApiResponse(responseCode = "503", ref = "#/components/responses/ServiceUnavailable")
    public DiscountResponse validate(@Valid @RequestBody ValidateRequest request) {
        return DiscountResponse.from(promoCodes.validate(request.code(), request.userId(), request.amount()));
    }

    @PostMapping("/apply")
    @Operation(summary = "[Interne] Appliquer un code à une réservation",
            description = "Revalide puis enregistre l'utilisation (201). Idempotent : même reservationId et même code "
                    + "-> 200 avec la même utilisation ; autre code sur la même réservation -> 409. INTERNAL.")
    @ApiResponse(responseCode = "201", description = "Utilisation enregistrée")
    @ApiResponse(responseCode = "200", description = "Déjà appliqué à cette réservation (même utilisation)")
    @ApiResponse(responseCode = "400", ref = "#/components/responses/BadRequest")
    @ApiResponse(responseCode = "403", ref = "#/components/responses/Forbidden")
    @ApiResponse(responseCode = "404", ref = "#/components/responses/NotFound")
    @ApiResponse(responseCode = "409", ref = "#/components/responses/Conflict")
    @ApiResponse(responseCode = "422", ref = "#/components/responses/Unprocessable")
    @ApiResponse(responseCode = "502", ref = "#/components/responses/BadGateway")
    @ApiResponse(responseCode = "503", ref = "#/components/responses/ServiceUnavailable")
    public ResponseEntity<UsageResponse> apply(@Valid @RequestBody ApplyRequest request) {
        PromoCodeService.Applied applied = promoCodes.apply(request.code(), request.userId(), request.reservationId(),
                request.amount());
        UsageResponse body = UsageResponse.from(applied.usage(), applied.code());
        return ResponseEntity.status(applied.created() ? HttpStatus.CREATED : HttpStatus.OK).body(body);
    }
}
