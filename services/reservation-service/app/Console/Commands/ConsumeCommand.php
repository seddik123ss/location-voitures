<?php

namespace App\Console\Commands;

use App\Messaging\MessageProcessor;
use App\Messaging\Outcome;
use App\Messaging\RabbitConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * php artisan rabbitmq:consume (ou composer consume) : déclare reservation.payment_succeeded et
 * reservation.contract_closed (durables, avec leur .dlq), puis consomme avec ack manuel et prefetch 1.
 * Ctrl+C arrête proprement après le message en cours ; une coupure de RabbitMQ entraîne une reconnexion.
 */
final class ConsumeCommand extends Command
{
    protected $signature = 'rabbitmq:consume
        {--queue=* : Seulement ces files (par défaut : toutes celles du service)}
        {--declare-only : Déclare les files puis s\'arrête (débloque les producteurs sans consommer)}
        {--max-messages= : Arrêt après ce nombre de messages (démonstration et tests)}';

    protected $description = 'Déclare les files du service et consomme les messages RabbitMQ (Ctrl+C pour arrêter)';

    private bool $stopRequested = false;

    private int $handled = 0;

    public function handle(RabbitConnector $connector, MessageProcessor $processor): int
    {
        $queues = $this->option('queue') ?: config('rabbitmq.consume');
        $unknown = array_diff($queues, array_keys(MessageProcessor::HANDLERS));
        if ($unknown !== []) {
            $this->error('Files inconnues : '.implode(', ', $unknown));

            return self::INVALID;
        }
        $max = $this->option('max-messages') !== null ? max(1, (int) $this->option('max-messages')) : null;
        $this->installStopHandlers();

        while (! $this->stopRequested) {
            try {
                $connection = $connector->connect(readWriteTimeout: 30);
                $channel = $connection->channel();
                foreach ($queues as $queue) {
                    RabbitConnector::declareConsumedQueue($channel, $queue);
                    $this->log("File $queue déclarée (durable, rejets dans $queue.dlq)");
                }
                if ($this->option('declare-only')) {
                    $connection->close();

                    return self::SUCCESS;
                }

                $channel->basic_qos(0, (int) config('rabbitmq.prefetch'), false);
                foreach ($queues as $queue) {
                    $channel->basic_consume($queue, '', false, false, false, false,
                        fn (AMQPMessage $message) => $this->onMessage($processor, $queue, $message));
                }
                $this->log('En attente de messages sur '.implode(', ', $queues).' (Ctrl+C pour arrêter)');

                while (! $this->stopRequested && $channel->is_consuming() && ($max === null || $this->handled < $max)) {
                    try {
                        $channel->wait(null, false, 1); // 1 s : réagir vite à Ctrl+C
                    } catch (AMQPTimeoutException) {
                        // aucun message pendant 1 s
                    }
                    if (function_exists('pcntl_signal_dispatch')) {
                        pcntl_signal_dispatch();
                    }
                }
                $channel->close();
                $connection->close();
                if ($max !== null && $this->handled >= $max) {
                    break;
                }
            } catch (AMQPProtocolChannelException $e) {
                // Ex. PRECONDITION_FAILED : la file existe déjà avec d'autres arguments. Pas la peine de réessayer.
                $this->error('RabbitMQ refuse la déclaration des files : '.$e->getMessage());
                $this->line('Supprimez la file dans la console http://localhost:15672 (Queues and Streams) puis relancez.');

                return self::FAILURE;
            } catch (\Throwable $e) {
                if ($this->stopRequested) {
                    break;
                }
                $this->log('RabbitMQ indisponible ('.$e->getMessage().'), nouvel essai dans '.config('rabbitmq.reconnect_delay').' s');
                Sleep::for((int) config('rabbitmq.reconnect_delay'))->seconds();
            }
        }
        $this->log("Arrêt du consommateur ({$this->handled} message(s) traité(s))");

        return self::SUCCESS;
    }

    private function onMessage(MessageProcessor $processor, string $queue, AMQPMessage $message): void
    {
        $headers = $message->has('application_headers') ? $message->get('application_headers') : null;
        $correlationId = $headers instanceof AMQPTable ? ($headers->getNativeData()['X-Correlation-Id'] ?? null) : null;

        $outcome = $processor->process($queue, $message->getBody(), $message->isRedelivered(), is_string($correlationId) ? $correlationId : null);
        match ($outcome) {
            Outcome::ACK => $message->ack(),
            Outcome::REQUEUE => $message->nack(true),
            Outcome::DEAD_LETTER => $message->nack(false), // -> <file>.dlq par x-dead-letter-routing-key
        };
        $this->handled++;
        $this->log("[$queue] {$outcome->value} : {$processor->summary}");
    }

    private function installStopHandlers(): void
    {
        if (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(function (int $event): void {
                $this->stopRequested = true;
            });
        }
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function (): void {
                $this->stopRequested = true;
            });
            pcntl_signal(SIGTERM, function (): void {
                $this->stopRequested = true;
            });
        }
    }

    private function log(string $message): void
    {
        $this->line(sprintf('[%s] %s', gmdate('H:i:s'), $message));
    }
}
