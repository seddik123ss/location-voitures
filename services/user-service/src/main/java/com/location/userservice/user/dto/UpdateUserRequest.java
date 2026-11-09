package com.location.userservice.user.dto;

import com.location.userservice.user.Role;
import io.swagger.v3.oas.annotations.media.Schema;
import jakarta.validation.constraints.Email;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Pattern;
import jakarta.validation.constraints.Size;

@Schema(description = "Modification complète d'un profil (PUT)")
public record UpdateUserRequest(
        @Schema(example = "Client")
        @NotBlank @Size(max = 100) String firstname,

        @Schema(example = "Un")
        @NotBlank @Size(max = 100) String lastname,

        @Schema(example = "client1@test.tn")
        @NotBlank @Email(regexp = UserRules.EMAIL_REGEX, message = UserRules.EMAIL_MESSAGE) @Size(max = 255) String email,

        @Schema(example = "+21620111111")
        @Pattern(regexp = UserRules.PHONE_REGEX, message = UserRules.PHONE_MESSAGE) String phone,

        @Schema(description = "Seul un ADMIN peut changer le rôle", example = "CLIENT")
        @NotNull Role role,

        @Schema(description = "Seul un ADMIN peut changer le statut", example = "true")
        @NotNull Boolean active) {
}
