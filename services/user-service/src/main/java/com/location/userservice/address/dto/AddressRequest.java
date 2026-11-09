package com.location.userservice.address.dto;

import io.swagger.v3.oas.annotations.media.Schema;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.Pattern;
import jakarta.validation.constraints.Size;

@Schema(description = "Création ou modification d'une adresse")
public record AddressRequest(
        @Schema(example = "12 Rue de Marseille")
        @NotBlank @Size(max = 255) String street,

        @Schema(example = "Tunis")
        @NotBlank @Size(max = 100) String city,

        @Schema(description = "Code postal tunisien : 4 chiffres", example = "1000")
        @NotBlank @Pattern(regexp = "^[0-9]{4}$", message = "Code postal invalide (4 chiffres)") String zipCode) {
}
