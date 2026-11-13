package com.location.loyaltyservice.messaging;

/**
 * Décision pour un message reçu :
 * ACK (traité, ou doublon ignoré), REQUEUE (erreur passagère, première fois : remis en file une seule fois),
 * DEAD_LETTER (message invalide, ou nouvel échec après la remise en file : nack sans remise, donc &lt;file&gt;.dlq).
 */
public enum Outcome {
    ACK, REQUEUE, DEAD_LETTER
}
