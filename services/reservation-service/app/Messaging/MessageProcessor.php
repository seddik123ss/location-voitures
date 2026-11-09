<?php

namespace App\Messaging;

use App\Messaging\Handlers\ContractClosedHandler;
use App\Messaging\Handlers\MessageHandler;
use App\Messaging\Handlers\PaymentSucceededHandler;
use App\Models\ProcessedEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Traite un message reçu, indépendamment de RabbitMQ (testable sans broker) :
 * - enveloppe illisible, type inattendu, réservation inconnue -> DEAD_LETTER (directement en .dlq) ;
 * - eventId déjà traité -> ACK sans rien refaire (idempotence) ;
 * - sinon le traitement et l'enregistrement de l'eventId sont faits dans une seule transaction ;
 * - erreur passagère (base indisponible...) -> REQUEUE la première fois, DEAD_LETTER si le message a déjà été remis.
 */
final class MessageProcessor
{
    /** @var array<string, class-string<MessageHandler>> */
    public const HANDLERS = [
        'reservation.payment_succeeded' => PaymentSucceededHandler::class,
        'reservation.contract_closed' => ContractClosedHandler::class,
    ];

    /** Résumé du dernier message traité, pour la console du consommateur. */
    public string $summary = '';

    public function process(string $queue, string $body, bool $redelivered, ?string $correlationId = null): Outcome
    {
        $correlationId = $correlationId !== null && $correlationId !== '' ? $correlationId : (string) Str::uuid();
        app()->instance('correlationId', $correlationId);
        $log = Log::withContext(['queue' => $queue, 'correlationId' => $correlationId]);

        try {
            $envelope = Envelope::parse($body);
            $handler = app(self::HANDLERS[$queue] ?? throw new InvalidMessage("Aucun traitement pour la file $queue"));
            if ($envelope->type !== $handler->type()) {
                throw new InvalidMessage("Type {$envelope->type} inattendu sur $queue ({$handler->type()} attendu)");
            }
        } catch (InvalidMessage $e) {
            $log->error($this->summary = "Message rejeté vers $queue.dlq : ".$e->getMessage());

            return Outcome::DEAD_LETTER;
        }

        try {
            $outcome = DB::transaction(function () use ($envelope, $handler, $queue): ?string {
                if (ProcessedEvent::query()->whereKey($envelope->eventId)->exists()) {
                    return null;
                }
                $outcome = $handler->handle($envelope);
                ProcessedEvent::query()->create([
                    'event_id' => $envelope->eventId,
                    'type' => $envelope->type,
                    'queue' => $queue,
                    'outcome' => Str::limit($outcome, 250),
                    'processed_at' => Carbon::now(),
                ]);

                return $outcome;
            });
        } catch (InvalidMessage $e) {
            $log->error($this->summary = "{$envelope->type} {$envelope->eventId} rejeté vers $queue.dlq : ".$e->getMessage());

            return Outcome::DEAD_LETTER;
        } catch (\Throwable $e) {
            report($e);
            if ($redelivered) {
                $log->error($this->summary = "{$envelope->type} {$envelope->eventId} : nouvel échec après remise en file, envoyé dans $queue.dlq : ".$e->getMessage());

                return Outcome::DEAD_LETTER;
            }
            $log->warning($this->summary = "{$envelope->type} {$envelope->eventId} : échec, remis en file une fois : ".$e->getMessage());

            return Outcome::REQUEUE;
        }

        $log->info($this->summary = $outcome === null
            ? "{$envelope->type} {$envelope->eventId} déjà traité : ignoré"
            : "{$envelope->type} {$envelope->eventId} traité : $outcome");

        return Outcome::ACK;
    }
}
