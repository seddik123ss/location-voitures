package com.location.loyaltyservice.account;

import com.location.loyaltyservice.account.dto.AccountResponse;
import com.location.loyaltyservice.account.dto.AdjustRequest;
import com.location.loyaltyservice.account.dto.RedeemRequest;
import com.location.loyaltyservice.account.dto.TransactionResponse;
import com.location.loyaltyservice.common.PageResponse;
import com.location.loyaltyservice.promo.PromoCode;
import com.location.loyaltyservice.promo.dto.PromoCodeResponse;
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
import java.util.List;
import java.util.UUID;
import org.springframework.http.ResponseEntity;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;

@RestController
@RequestMapping("/api/loyalty")
@Tag(name = "Fidélité", description = "Comptes de points, historique, conversion en bons, ajustements")
public class LoyaltyController {

    private final LoyaltyService loyalty;
    private final AccessGuard access;
    private final Clock clock;

    public LoyaltyController(LoyaltyService loyalty, AccessGuard access, Clock clock) {
        this.loyalty = loyalty;
        this.access = access;
        this.clock = clock;
    }

    @GetMapping("/me")
    @PreAuthorize("hasRole('CLIENT')")
    @Operation(summary = "Mon compte", description = "Solde, cumul, niveau et points manquants pour le niveau suivant. CLIENT.")
    @ApiResponse(responseCode = "200", description = "Compte du client connecté")
    @ApiResponse(responseCode = "401", ref = "#/components/responses/Unauthorized")
    @ApiResponse(responseCode = "403", ref = "#/components/responses/Forbidden")
    @ApiResponse(responseCode = "404", ref = "#/components/responses/NotFound")
    public AccountResponse me() {
        return AccountResponse.from(loyalty.getAccount(access.requireUserId()));
    }

    @GetMapping("/me/transactions")
    @PreAuthorize("hasRole('CLIENT')")
    @Operation(summary = "Mon historique de points", description = "Paginé, du plus récent au plus ancien. CLIENT.")
    @ApiResponse(responseCode = "200", description = "Page de l'historique")
    @ApiResponse(responseCode = "400", ref = "#/components/responses/BadRequest")
    @ApiResponse(responseCode = "401", ref = "#/components/responses/Unauthorized")
    @ApiResponse(responseCode = "404", ref = "#/components/responses/NotFound")
    public PageResponse<TransactionResponse> myTransactions(
            @RequestParam(defaultValue = "0") @Min(0) int page,
            @RequestParam(defaultValue = "20") @Min(1) @Max(100) int size) {
        return PageResponse.from(loyalty.history(access.requireUserId(), page, size), TransactionResponse::from);
    }

    @PostMapping("/me/redeem")
    @PreAuthorize("hasRole('CLIENT')")
    @Operation(summary = "Convertir des points en bon",
            description = "Multiples de 100 ; 100 points = 10.000 TND. Crée un bon personnel LOY-XXXXXX (1 utilisation, 90 jours). "
                    + "Pas un multiple de 100 ou solde insuffisant : 422 (field points). CLIENT.")
    @ApiResponse(responseCode = "201", description = "Bon créé")
    @ApiResponse(responseCode = "400", ref = "#/components/responses/BadRequest")
    @ApiResponse(responseCode = "401", ref = "#/components/responses/Unauthorized")
    @ApiResponse(responseCode = "404", ref = "#/components/responses/NotFound")
    @ApiResponse(responseCode = "422", ref = "#/components/responses/Unprocessable")
    public ResponseEntity<PromoCodeResponse> redeem(@Valid @RequestBody RedeemRequest request) {
        PromoCode voucher = loyalty.redeem(access.requireUserId(), request.points());
        return ResponseEntity.created(URI.create("/api/loyalty/me/vouchers"))
                .body(PromoCodeResponse.from(voucher, LocalDate.now(clock)));
    }

    @GetMapping("/me/vouchers")
    @PreAuthorize("hasRole('CLIENT')")
    @Operation(summary = "Mes bons personnels non utilisés", description = "Actifs, non utilisés et non expirés. CLIENT.")
    @ApiResponse(responseCode = "200", description = "Liste des bons")
    @ApiResponse(responseCode = "401", ref = "#/components/responses/Unauthorized")
    @ApiResponse(responseCode = "404", ref = "#/components/responses/NotFound")
    public List<PromoCodeResponse> myVouchers() {
        LocalDate today = LocalDate.now(clock);
        return loyalty.vouchers(access.requireUserId()).stream().map(p -> PromoCodeResponse.from(p, today)).toList();
    }

    @GetMapping("/accounts")
    @PreAuthorize("hasAnyRole('AGENT','ADMIN')")
    @Operation(summary = "Liste des comptes", description = "Filtre facultatif par niveau ; tri par cumul décroissant. AGENT, ADMIN.")
    @ApiResponse(responseCode = "200", description = "Page de comptes")
    @ApiResponse(responseCode = "400", ref = "#/components/responses/BadRequest")
    @ApiResponse(responseCode = "401", ref = "#/components/responses/Unauthorized")
    @ApiResponse(responseCode = "403", ref = "#/components/responses/Forbidden")
    public PageResponse<AccountResponse> accounts(
            @RequestParam(required = false) Tier tier,
            @RequestParam(defaultValue = "0") @Min(0) int page,
            @RequestParam(defaultValue = "20") @Min(1) @Max(100) int size) {
        return PageResponse.from(loyalty.listAccounts(tier, page, size), AccountResponse::from);
    }

    @GetMapping("/accounts/{userId}")
    @PreAuthorize("hasAnyRole('AGENT','ADMIN') or @access.isSelf(#userId)")
    @Operation(summary = "Détail d'un compte", description = "AGENT, ADMIN ou le client lui-même.")
    @ApiResponse(responseCode = "200", description = "Compte")
    @ApiResponse(responseCode = "401", ref = "#/components/responses/Unauthorized")
    @ApiResponse(responseCode = "403", ref = "#/components/responses/Forbidden")
    @ApiResponse(responseCode = "404", ref = "#/components/responses/NotFound")
    public AccountResponse account(@PathVariable UUID userId) {
        return AccountResponse.from(loyalty.getAccount(userId));
    }

    @PostMapping("/accounts/{userId}/adjust")
    @PreAuthorize("hasRole('ADMIN')")
    @Operation(summary = "Ajuster les points d'un client",
            description = "Geste commercial (positif, compte dans le cumul) ou correction (négative, sans faire passer le "
                    + "solde sous 0 ; le niveau ne baisse jamais). Motif obligatoire. ADMIN.")
    @ApiResponse(responseCode = "200", description = "Compte après ajustement")
    @ApiResponse(responseCode = "400", ref = "#/components/responses/BadRequest")
    @ApiResponse(responseCode = "401", ref = "#/components/responses/Unauthorized")
    @ApiResponse(responseCode = "403", ref = "#/components/responses/Forbidden")
    @ApiResponse(responseCode = "404", ref = "#/components/responses/NotFound")
    @ApiResponse(responseCode = "422", ref = "#/components/responses/Unprocessable")
    public AccountResponse adjust(@PathVariable UUID userId, @Valid @RequestBody AdjustRequest request) {
        return AccountResponse.from(loyalty.adjust(userId, request.points(), request.reason()));
    }
}
