package com.location.userservice.user.dto;

import com.location.userservice.user.Role;
import com.location.userservice.user.User;
import io.swagger.v3.oas.annotations.media.Schema;
import java.time.Instant;
import java.util.UUID;

@Schema(description = "Utilisateur dans une liste")
public record UserSummaryResponse(
        @Schema(example = "f4d1bab3-468d-428d-b02c-b44341430bfd") UUID id,
        @Schema(example = "Client") String firstname,
        @Schema(example = "Un") String lastname,
        @Schema(example = "client1@test.tn") String email,
        @Schema(example = "+21620111111") String phone,
        @Schema(example = "CLIENT") Role role,
        @Schema(example = "true") boolean active,
        @Schema(example = "2026-11-01T08:00:00Z") Instant createdAt) {

    public static UserSummaryResponse from(User u) {
        return new UserSummaryResponse(u.getId(), u.getFirstname(), u.getLastname(), u.getEmail(),
                u.getPhone(), u.getRole(), u.isActive(), u.getCreatedAt());
    }
}
