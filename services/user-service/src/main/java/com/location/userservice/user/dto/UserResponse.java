package com.location.userservice.user.dto;

import com.location.userservice.address.dto.AddressResponse;
import com.location.userservice.license.dto.LicenseResponse;
import com.location.userservice.user.Role;
import com.location.userservice.user.User;
import io.swagger.v3.oas.annotations.media.Schema;
import java.time.Instant;
import java.util.List;
import java.util.UUID;

@Schema(description = "Profil complet : utilisateur, adresses et permis")
public record UserResponse(
        @Schema(example = "f4d1bab3-468d-428d-b02c-b44341430bfd") UUID id,
        @Schema(example = "Client") String firstname,
        @Schema(example = "Un") String lastname,
        @Schema(example = "client1@test.tn") String email,
        @Schema(example = "+21620111111") String phone,
        @Schema(example = "CLIENT") Role role,
        @Schema(example = "true") boolean active,
        @Schema(example = "2026-11-01T08:00:00Z") Instant createdAt,
        @Schema(example = "2026-11-01T08:00:00Z") Instant updatedAt,
        @Schema(description = "Permis de conduire, ou null") LicenseResponse license,
        List<AddressResponse> addresses) {

    public static UserResponse from(User u, LicenseResponse license, List<AddressResponse> addresses) {
        return new UserResponse(u.getId(), u.getFirstname(), u.getLastname(), u.getEmail(), u.getPhone(),
                u.getRole(), u.isActive(), u.getCreatedAt(), u.getUpdatedAt(), license, addresses);
    }
}
