<?php

namespace App\Messaging;

/** Suite donnée à un message reçu. */
enum Outcome: string
{
    /** Traité (ou doublon déjà traité) : ack. */
    case ACK = 'ack';

    /** Erreur passagère, première livraison : nack avec remise en file (une seule fois). */
    case REQUEUE = 'requeue';

    /** Message invalide, ou nouvel échec après la remise en file : nack sans remise -> <file>.dlq. */
    case DEAD_LETTER = 'dead-letter';
}
