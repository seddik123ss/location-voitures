package com.location.loyaltyservice.account;

import com.location.loyaltyservice.account.dto.InternalLoyaltyResponse;
import io.swagger.v3.oas.annotations.Operation;
import io.swagger.v3.oas.annotations.responses.ApiResponse;
import io.swagger.v3.oas.annotations.tags.Tag;
import java.util.UUID;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.RestController;

/** Route entre services : rôle INTERNAL (SecurityConfig), jamais exposée par la Gateway. */
@RestController
@Tag(name = "Interne", description = "Routes entre services (rôle INTERNAL, hors Gateway)")
public class InternalLoyaltyController {

    private final LoyaltyService loyalty;

    public InternalLoyaltyController(LoyaltyService loyalty) {
        this.loyalty = loyalty;
    }

    @GetMapping("/internal/loyalty/{userId}")
    @Operation(summary = "[Interne] Niveau et solde d'un client", description = "{userId, tier, balance, status}. INTERNAL.")
    @ApiResponse(responseCode = "200", description = "Compte")
    @ApiResponse(responseCode = "401", ref = "#/components/responses/Unauthorized")
    @ApiResponse(responseCode = "403", ref = "#/components/responses/Forbidden")
    @ApiResponse(responseCode = "404", ref = "#/components/responses/NotFound")
    public InternalLoyaltyResponse get(@PathVariable UUID userId) {
        return InternalLoyaltyResponse.from(loyalty.getAccount(userId));
    }
}
