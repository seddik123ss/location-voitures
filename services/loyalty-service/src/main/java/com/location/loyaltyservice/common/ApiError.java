package com.location.loyaltyservice.common;

import io.swagger.v3.oas.annotations.media.Schema;
import java.util.List;
import org.springframework.http.HttpStatus;

/** Format d'erreur commun à tous les services (docs/conventions.md). */
@Schema(name = "ApiError", description = "Format d'erreur commun")
public record ApiError(
        @Schema(example = "409") int status,
        @Schema(example = "CONFLICT") String error,
        @Schema(example = "Ce code promo existe déjà") String message,
        List<FieldDetail> details) {

    @Schema(name = "ApiErrorDetail")
    public record FieldDetail(
            @Schema(example = "code") String field,
            @Schema(example = "Ce code promo existe déjà") String message) {
    }

    public static ApiError of(HttpStatus status, String message) {
        return new ApiError(status.value(), status.name(), message, List.of());
    }

    public static ApiError of(HttpStatus status, String message, List<FieldDetail> details) {
        return new ApiError(status.value(), status.name(), message, details == null ? List.of() : details);
    }
}
