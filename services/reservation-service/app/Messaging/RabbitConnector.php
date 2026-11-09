<?php

namespace App\Messaging;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Wire\AMQPTable;

/** Connexion à RabbitMQ à partir de RABBITMQ_URL (amqp://user:pass@hote:port/vhost) et déclaration des files. */
final class RabbitConnector
{
    public function connect(int $readWriteTimeout = 10): AMQPStreamConnection
    {
        $url = (string) config('rabbitmq.url');
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host']) || ! in_array($parts['scheme'] ?? '', ['amqp'], true)) {
            throw new \InvalidArgumentException('RABBITMQ_URL invalide (attendu : amqp://user:motdepasse@hote:5672)');
        }
        $vhost = isset($parts['path']) && $parts['path'] !== '/' && $parts['path'] !== '' ? rawurldecode(substr($parts['path'], 1)) : '/';

        return new AMQPStreamConnection(
            $parts['host'],
            $parts['port'] ?? 5672,
            rawurldecode($parts['user'] ?? 'guest'),
            rawurldecode($parts['pass'] ?? 'guest'),
            $vhost,
            connection_timeout: (float) config('rabbitmq.connection_timeout'),
            read_write_timeout: (float) $readWriteTimeout,
        );
    }

    /**
     * Déclare une file consommée par ce service : durable, avec sa file <nom>.dlq (durable) qui reçoit les messages
     * rejetés (nack sans remise). Mêmes arguments que ceux utilisés par payment-service pour ses propres files.
     */
    public static function declareConsumedQueue(AMQPChannel $channel, string $queue): void
    {
        $channel->queue_declare("$queue.dlq", false, true, false, false);
        $channel->queue_declare($queue, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange' => '',
            'x-dead-letter-routing-key' => "$queue.dlq",
        ]));
    }
}
