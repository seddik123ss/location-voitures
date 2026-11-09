package com.location.userservice.user.dto;

import com.location.userservice.license.DriverLicense;
import com.location.userservice.user.Role;
import com.location.userservice.user.User;
import io.swagger.v3.oas.annotations.media.Schema;
import java.time.LocalDate;
import java.util.UUID;

/**
 * Réponse de GET /internal/users/{id}, au format fixé dans docs/internal-api.md :
 * {id, firstname, lastname, email, phone, role, active, license: {number, category, issueDate, expiryDate, verified} ou null}
 */
@Schema(description = "Utilisateur + permis, pour les autres services (reservation-service)")
public record InternalUserResponse(
        @Schema(example = "f4d1bab3-468d-428d-b02c-b44341430bfd") UUID id,
        @Schema(example = "Client") String firstname,
        @Schema(example = "Un") String lastname,
        @Schema(example = "client1@test.tn") String email,
        @Schema(example = "+21620111111") String phone,
        @Schema(example = "CLIENT") Role role,
        @Schema(example = "true") boolean active,
        @Schema(description = "Permis, ou null si l'utilisateur n'en a pas") License license) {

    @Schema(name = "InternalLicense")
    public record License(
            @Schema(example = "TN-2020-000111") String number,
            @Schema(example = "B") String category,
            @Schema(example = "2020-03-15") LocalDate issueDate,
            @Schema(example = "2030-03-15") LocalDate expiryDate,
            @Schema(example = "true") boolean verified) {
    }

    public static InternalUserResponse from(User u, DriverLicense l) {
        License license = l == null ? null
                : new License(l.getNumber(), l.getCategory(), l.getIssueDate(), l.getExpiryDate(), l.isVerified());
        return new InternalUserResponse(u.getId(), u.getFirstname(), u.getLastname(), u.getEmail(), u.getPhone(),
                u.getRole(), u.isActive(), license);
    }
}
