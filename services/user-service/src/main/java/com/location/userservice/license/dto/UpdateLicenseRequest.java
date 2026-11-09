package com.location.userservice.license.dto;

import io.swagger.v3.oas.annotations.media.Schema;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Pattern;
import jakarta.validation.constraints.Size;
import java.time.LocalDate;

@Schema(description = "Modification complète d'un permis (PUT). Toute modification annule la vérification.")
public record UpdateLicenseRequest(
        @Schema(example = "TN-2020-000111")
        @NotBlank @Size(max = 30) String number,

        @Schema(example = "B")
        @NotBlank @Pattern(regexp = LicenseRules.CATEGORY_REGEX, message = LicenseRules.CATEGORY_MESSAGE) String category,

        @Schema(example = "2020-03-15")
        @NotNull LocalDate issueDate,

        @Schema(example = "2030-03-15")
        @NotNull LocalDate expiryDate) {
}
