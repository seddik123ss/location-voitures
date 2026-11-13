package com.location.loyaltyservice.account.dto;

import com.location.loyaltyservice.account.AccountStatus;
import com.location.loyaltyservice.account.LoyaltyAccount;
import com.location.loyaltyservice.account.Tier;
import io.swagger.v3.oas.annotations.media.Schema;
import java.util.UUID;

@Schema(description = "Niveau et solde d'un client, pour les autres services")
public record InternalLoyaltyResponse(UUID userId, Tier tier, @Schema(example = "1240") int balance, AccountStatus status) {

    public static InternalLoyaltyResponse from(LoyaltyAccount a) {
        return new InternalLoyaltyResponse(a.getUserId(), a.getTier(), a.getBalance(), a.getStatus());
    }
}
