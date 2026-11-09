<?php

/*
 * RabbitMQ (docs/events/README.md). Exchange par défaut : routing key = nom de la file.
 * Files consommées (déclarées ici, durables, avec leur <file>.dlq) et files où l'on publie (déclarées par leur consommateur).
 */
return [
    'url' => env('RABBITMQ_URL', 'amqp://guest:guest@localhost:5672'),

    'consume' => [
        'reservation.payment_succeeded', // payment -> PaymentSucceeded (RENTAL : PENDING -> CONFIRMED)
        'reservation.contract_closed',   // contract -> ContractClosed (-> COMPLETED)
    ],

    'publish' => [
        'reservation_cancelled' => 'payment.reservation_cancelled', // -> payment-service (remboursements)
        'reservation_completed' => 'ml.reservation_completed',      // -> ml-service (jeu d'entraînement)
    ],

    'prefetch' => 1,
    'connection_timeout' => 3,
    // Délai max d'attente de la confirmation du broker (publisher confirms), en secondes.
    'confirm_timeout' => 5,
    // Pause avant une nouvelle connexion du consommateur après une coupure, en secondes.
    'reconnect_delay' => 5,
];
