package com.location.userservice.license.dto;

import io.swagger.v3.oas.annotations.media.Schema;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Pattern;
import jakarta.validation.constraints.Size;
import java.time.LocalDate;
import java.util.UUID;

@Schema(description = "Ajout du permis d'un utilisateur (un seul permis par utilisateur)")
public record CreateLicenseRequest(
        @Schema(description = "Propriétaire du permis", example = "11c9b774-201e-4a8c-be43-6b084b52f4c7")
        @NotNull UUID userId,

        @Schema(example = "TN-2022-000333")
        @NotBlank @Size(max = 30) String number,

        @Schema(description = "Catégorie : A, A1, B, BE, C, CE, D, D1, DE, H", example = "B")
        @NotBlank @Pattern(regexp = LicenseRules.CATEGORY_REGEX, message = LicenseRules.CATEGORY_MESSAGE) String category,

        @Schema(example = "2022-05-10")
        @NotNull LocalDate issueDate,

        @Schema(description = "Doit être aujourd'hui ou plus tard, sinon 422", example = "2032-05-10")
        @NotNull LocalDate expiryDate) {
}
