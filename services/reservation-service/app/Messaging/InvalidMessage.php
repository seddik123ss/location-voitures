<?php

namespace App\Messaging;

/**
 * Message qui ne pourra jamais être traité (JSON illisible, champ manquant, réservation inconnue, statut incohérent) :
 * envoyé directement dans <file>.dlq, sans nouvel essai.
 */
final class InvalidMessage extends \RuntimeException
{
}
