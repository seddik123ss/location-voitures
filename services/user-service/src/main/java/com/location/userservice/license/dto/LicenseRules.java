package com.location.userservice.license.dto;

/** Catégories de permis acceptées (une autre valeur -> 422). */
public final class LicenseRules {

    public static final String CATEGORY_REGEX = "^(A|A1|B|BE|C|CE|D|D1|DE|H)$";
    public static final String CATEGORY_MESSAGE = "Catégorie invalide (A, A1, B, BE, C, CE, D, D1, DE, H)";

    private LicenseRules() {
    }
}
