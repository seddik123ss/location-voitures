package com.location.loyaltyservice.common;

import jakarta.validation.ConstraintViolationException;
import java.util.List;
import java.util.Set;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.dao.DataIntegrityViolationException;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.http.converter.HttpMessageNotReadableException;
import org.springframework.security.access.AccessDeniedException;
import org.springframework.validation.FieldError;
import org.springframework.web.HttpRequestMethodNotSupportedException;
import org.springframework.web.bind.MethodArgumentNotValidException;
import org.springframework.web.bind.MissingServletRequestParameterException;
import org.springframework.web.bind.annotation.ExceptionHandler;
import org.springframework.web.bind.annotation.RestControllerAdvice;
import org.springframework.web.method.annotation.HandlerMethodValidationException;
import org.springframework.web.method.annotation.MethodArgumentTypeMismatchException;
import org.springframework.web.servlet.resource.NoResourceFoundException;

/**
 * Traduit les exceptions en réponses au format commun
 * {"status","error","message","details":[{"field","message"}]}.
 *
 * <ul>
 *   <li>400 : requête mal formée (JSON illisible, paramètre invalide, champ obligatoire absent)</li>
 *   <li>422 : données présentes mais invalides (dates, montants, points...)</li>
 * </ul>
 */
@RestControllerAdvice
public class GlobalExceptionHandler {

    private static final Logger log = LoggerFactory.getLogger(GlobalExceptionHandler.class);

    /** Contraintes qui signifient "champ absent" -> 400. Les autres (format, taille...) -> 422. */
    private static final Set<String> MISSING_CODES = Set.of("NotNull", "NotBlank", "NotEmpty");

    @ExceptionHandler(ApiException.class)
    public ResponseEntity<ApiError> handleApi(ApiException ex) {
        return build(ex.getStatus(), ex.getMessage(), ex.getDetails());
    }

    @ExceptionHandler(MethodArgumentNotValidException.class)
    public ResponseEntity<ApiError> handleBodyValidation(MethodArgumentNotValidException ex) {
        List<FieldError> errors = ex.getBindingResult().getFieldErrors();
        List<ApiError.FieldDetail> details = errors.stream()
                .map(e -> new ApiError.FieldDetail(e.getField(), e.getDefaultMessage()))
                .toList();
        boolean missing = errors.stream().anyMatch(e -> MISSING_CODES.contains(e.getCode()));
        return missing
                ? build(HttpStatus.BAD_REQUEST, "Champs obligatoires manquants", details)
                : build(HttpStatus.UNPROCESSABLE_ENTITY, "Données invalides", details);
    }

    @ExceptionHandler(HandlerMethodValidationException.class)
    public ResponseEntity<ApiError> handleParamValidation(HandlerMethodValidationException ex) {
        List<ApiError.FieldDetail> details = ex.getAllValidationResults().stream()
                .flatMap(r -> r.getResolvableErrors().stream()
                        .map(e -> new ApiError.FieldDetail(r.getMethodParameter().getParameterName(),
                                e.getDefaultMessage())))
                .toList();
        return build(HttpStatus.BAD_REQUEST, "Paramètres invalides", details);
    }

    @ExceptionHandler(ConstraintViolationException.class)
    public ResponseEntity<ApiError> handleConstraint(ConstraintViolationException ex) {
        List<ApiError.FieldDetail> details = ex.getConstraintViolations().stream()
                .map(v -> new ApiError.FieldDetail(v.getPropertyPath().toString(), v.getMessage()))
                .toList();
        return build(HttpStatus.BAD_REQUEST, "Paramètres invalides", details);
    }

    @ExceptionHandler(HttpMessageNotReadableException.class)
    public ResponseEntity<ApiError> handleUnreadable(HttpMessageNotReadableException ex) {
        return build(HttpStatus.BAD_REQUEST, "Corps de la requête illisible (JSON invalide ou valeur au mauvais format)", null);
    }

    @ExceptionHandler(MethodArgumentTypeMismatchException.class)
    public ResponseEntity<ApiError> handleTypeMismatch(MethodArgumentTypeMismatchException ex) {
        return build(HttpStatus.BAD_REQUEST, "Paramètre invalide : " + ex.getName(),
                List.of(new ApiError.FieldDetail(ex.getName(), "Valeur au mauvais format")));
    }

    @ExceptionHandler(MissingServletRequestParameterException.class)
    public ResponseEntity<ApiError> handleMissingParam(MissingServletRequestParameterException ex) {
        return build(HttpStatus.BAD_REQUEST, "Paramètre obligatoire manquant : " + ex.getParameterName(),
                List.of(new ApiError.FieldDetail(ex.getParameterName(), "Obligatoire")));
    }

    /** Filet de sécurité : contrainte unique violée entre la vérification et l'écriture (accès concurrents). */
    @ExceptionHandler(DataIntegrityViolationException.class)
    public ResponseEntity<ApiError> handleIntegrity(DataIntegrityViolationException ex) {
        return build(HttpStatus.CONFLICT, "Conflit avec une donnée existante", null);
    }

    @ExceptionHandler(AccessDeniedException.class)
    public ResponseEntity<ApiError> handleAccessDenied(AccessDeniedException ex) {
        return build(HttpStatus.FORBIDDEN, "Accès refusé : rôle insuffisant ou ressource d'un autre utilisateur", null);
    }

    @ExceptionHandler(NoResourceFoundException.class)
    public ResponseEntity<ApiError> handleNoRoute(NoResourceFoundException ex) {
        return build(HttpStatus.NOT_FOUND, "Route introuvable : /" + ex.getResourcePath(), null);
    }

    @ExceptionHandler(HttpRequestMethodNotSupportedException.class)
    public ResponseEntity<ApiError> handleMethod(HttpRequestMethodNotSupportedException ex) {
        return build(HttpStatus.METHOD_NOT_ALLOWED, "Méthode " + ex.getMethod() + " non supportée sur cette route", null);
    }

    @ExceptionHandler(Exception.class)
    public ResponseEntity<ApiError> handleOther(Exception ex) {
        log.error("Erreur inattendue", ex);
        return build(HttpStatus.INTERNAL_SERVER_ERROR, "Erreur interne du service", null);
    }

    private ResponseEntity<ApiError> build(HttpStatus status, String message, List<ApiError.FieldDetail> details) {
        return ResponseEntity.status(status).body(ApiError.of(status, message, details));
    }
}
