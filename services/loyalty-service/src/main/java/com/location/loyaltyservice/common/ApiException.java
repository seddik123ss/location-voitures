package com.location.loyaltyservice.common;

import java.util.List;
import org.springframework.http.HttpStatus;

/** Exception métier traduite en réponse d'erreur commune par {@link GlobalExceptionHandler}. */
public class ApiException extends RuntimeException {

    private final HttpStatus status;
    private final List<ApiError.FieldDetail> details;

    public ApiException(HttpStatus status, String message) {
        this(status, message, null);
    }

    public ApiException(HttpStatus status, String message, String field) {
        super(message);
        this.status = status;
        this.details = field == null ? List.of() : List.of(new ApiError.FieldDetail(field, message));
    }

    public HttpStatus getStatus() {
        return status;
    }

    public List<ApiError.FieldDetail> getDetails() {
        return details;
    }

    /** 404 : objet introuvable. */
    public static ApiException notFound(String message) {
        return new ApiException(HttpStatus.NOT_FOUND, message);
    }

    /** 409 : conflit métier (doublon). */
    public static ApiException conflict(String message, String field) {
        return new ApiException(HttpStatus.CONFLICT, message, field);
    }

    /** 422 : règle métier non respectée. */
    public static ApiException unprocessable(String message, String field) {
        return new ApiException(HttpStatus.UNPROCESSABLE_ENTITY, message, field);
    }

    /** 403 : action interdite pour ce rôle. */
    public static ApiException forbidden(String message) {
        return new ApiException(HttpStatus.FORBIDDEN, message);
    }
}
