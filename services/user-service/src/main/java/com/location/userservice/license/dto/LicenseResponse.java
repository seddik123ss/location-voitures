package com.location.userservice.license.dto;

import com.location.userservice.license.DriverLicense;
import io.swagger.v3.oas.annotations.media.Schema;
import java.time.LocalDate;
import java.util.UUID;

@Schema(description = "Permis de conduire")
public record LicenseResponse(
        @Schema(example = "edc2e0e3-709d-4caa-b617-53e4c5f128cf") UUID id,
        @Schema(example = "f4d1bab3-468d-428d-b02c-b44341430bfd") UUID userId,
        @Schema(example = "TN-2020-000111") String number,
        @Schema(example = "B") String category,
        @Schema(example = "2020-03-15") LocalDate issueDate,
        @Schema(example = "2030-03-15") LocalDate expiryDate,
        @Schema(description = "Vérifié par un AGENT ou un ADMIN", example = "true") boolean verified,
        @Schema(description = "Calculé : date d'expiration dépassée", example = "false") boolean expired) {

    public static LicenseResponse from(DriverLicense l, LocalDate today) {
        return new LicenseResponse(l.getId(), l.getUserId(), l.getNumber(), l.getCategory(),
                l.getIssueDate(), l.getExpiryDate(), l.isVerified(), l.isExpiredOn(today));
    }
}
