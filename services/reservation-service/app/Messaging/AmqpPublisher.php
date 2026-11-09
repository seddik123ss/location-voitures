<?php

namespace App\Messaging;

use App\Http\Middleware\CorrelationId;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Publication sur l'exchange par défaut (routing key = nom de la file, déclarée par son consommateur) :
 * message persistant (delivery mode 2), application/json, en-tête X-Correlation-Id, avec
 * publisher confirms + mandatory : la méthode ne rend la main que si RabbitMQ a bien pris le message en charge.
 */
class AmqpPublisher
{
    public function __construct(private readonly RabbitConnector $connector)
    {
    }

    /** @throws PublishFailed */
    public function publish(string $queue, Envelope $envelope): void
    {
        $returned = null;
        $nacked = false;
        $connection = null;
        try {
            $connection = $this->connector->connect();
            $channel = $connection->channel();
            $channel->confirm_select();
            $channel->set_return_listener(function (int $code, string $text) use (&$returned): void {
                $returned = "$code $text";
            });
            $channel->set_nack_handler(function () use (&$nacked): void {
                $nacked = true;
            });

            $message = new AMQPMessage($envelope->toJson(), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $envelope->eventId,
                'type' => $envelope->type,
                'timestamp' => time(),
                'application_headers' => new AMQPTable([CorrelationId::HEADER => CorrelationId::current()]),
            ]);
            $channel->basic_publish($message, '', $queue, true);
            $channel->wait_for_pending_acks_returns((float) config('rabbitmq.confirm_timeout'));
        } catch (\Throwable $e) {
            Log::error("Publication de {$envelope->type} {$envelope->eventId} sur $queue impossible : ".$e->getMessage());
            throw new PublishFailed("RabbitMQ injoignable : {$envelope->type} non publié", 0, $e);
        } finally {
            try {
                $connection?->close();
            } catch (\Throwable) {
                // connexion déjà perdue
            }
        }

        if ($returned !== null) {
            Log::error("{$envelope->type} {$envelope->eventId} retourné par RabbitMQ ($returned) : la file $queue n'existe pas encore");
            throw new PublishFailed("La file $queue n'existe pas encore dans RabbitMQ (son consommateur ne l'a pas déclarée) : {$envelope->type} non publié");
        }
        if ($nacked) {
            throw new PublishFailed("RabbitMQ a refusé {$envelope->type} sur $queue");
        }
        Log::info("{$envelope->type} {$envelope->eventId} publié sur $queue");
    }
}
