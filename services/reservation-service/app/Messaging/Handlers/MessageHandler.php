<?php

namespace App\Messaging\Handlers;

use App\Messaging\Envelope;
use App\Messaging\InvalidMessage;

/** Traitement d'un message, appelé dans la transaction qui enregistre son eventId. */
interface MessageHandler
{
    /** Type attendu dans l'enveloppe (ex. PaymentSucceeded). */
    public function type(): string;

    /**
     * @return string ce que le message a produit, pour le log et la table processed_events
     *
     * @throws InvalidMessage message impossible à traiter (direct en .dlq)
     */
    public function handle(Envelope $envelope): string;
}
