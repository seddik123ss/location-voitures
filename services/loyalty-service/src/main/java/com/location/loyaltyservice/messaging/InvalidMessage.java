package com.location.loyaltyservice.messaging;

/** Message impossible à traiter (JSON illisible, champ absent, réservation inconnue) : directement en .dlq, sans nouvel essai. */
public class InvalidMessage extends RuntimeException {

    public InvalidMessage(String message) {
        super(message);
    }
}
