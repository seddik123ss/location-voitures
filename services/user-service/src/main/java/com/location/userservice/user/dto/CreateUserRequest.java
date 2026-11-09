package com.location.userservice.user.dto;

import com.location.userservice.user.Role;
import io.swagger.v3.oas.annotations.media.Schema;
import jakarta.validation.constraints.Email;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Pattern;
import jakarta.validation.constraints.Size;
import java.util.UUID;

@Schema(description = "Création d'un profil utilisateur")
public record CreateUserRequest(
        @Schema(description = "Identifiant Keycloak (claim \"sub\") de l'utilisateur",
                example = "2b6c1f0e-7a1d-4f7e-9a51-3c1d2e4f5a6b")
        @NotNull UUID id,

        @Schema(example = "Sami")
        @NotBlank @Size(max = 100) String firstname,

        @Schema(example = "Ben Ali")
        @NotBlank @Size(max = 100) String lastname,

        @Schema(example = "sami.benali@test.tn")
        @NotBlank @Email(regexp = UserRules.EMAIL_REGEX, message = UserRules.EMAIL_MESSAGE) @Size(max = 255) String email,

        @Schema(description = "Facultatif. 8 à 15 chiffres, \"+\" initial autorisé", example = "+21698765432")
        @Pattern(regexp = UserRules.PHONE_REGEX, message = UserRules.PHONE_MESSAGE) String phone,

        @Schema(description = "Un utilisateur non ADMIN ne peut créer que son propre profil, avec le rôle CLIENT",
                example = "CLIENT")
        @NotNull Role role,

        @Schema(description = "Facultatif, true par défaut", example = "true")
        Boolean active) {
}
