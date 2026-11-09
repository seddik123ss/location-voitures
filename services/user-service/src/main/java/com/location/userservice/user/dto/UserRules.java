package com.location.userservice.user.dto;

/** Formats acceptés pour l'email et le téléphone (une valeur hors format -> 422). */
public final class UserRules {

    /** Doit contenir un domaine avec un point : "a@b" est refusé, "a@b.tn" accepté. */
    public static final String EMAIL_REGEX = "^[^@\\s]+@[^@\\s]+\\.[^@\\s]{2,}$";
    public static final String EMAIL_MESSAGE = "Format d'email invalide";

    /** 8 à 15 chiffres, "+" initial facultatif (ex. +21620111111 ou 20111111). */
    public static final String PHONE_REGEX = "^\\+?[0-9]{8,15}$";
    public static final String PHONE_MESSAGE = "Format de téléphone invalide (8 à 15 chiffres, + facultatif)";

    private UserRules() {
    }
}
