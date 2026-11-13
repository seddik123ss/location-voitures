package com.location.loyaltyservice.account.dto;

import io.swagger.v3.oas.annotations.media.Schema;
import jakarta.validation.constraints.NotNull;

@Schema(description = "Conversion de points en bon personnel (multiples de 100 ; 100 points = 10.000 TND)")
public record RedeemRequest(@NotNull @Schema(example = "200") Integer points) {
}
