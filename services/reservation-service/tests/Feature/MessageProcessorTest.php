<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Messaging\MessageProcessor;
use App\Messaging\Outcome;
use App\Models\ProcessedEvent;
use App\Models\Reservation;
use App\Support\DemoIds;
use Database\Seeders\DemoReservationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Messages reçus (docs/events/*.json) : effets, idempotence, remise en file, .dlq. */
class MessageProcessorTest extends TestCase
{
    use RefreshDatabase;

    private const PAYMENTS = 'reservation.payment_succeeded';
    private const CONTRACTS = 'reservation.contract_closed';

    private MessageProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09 10:00:00');
        $this->seed(DemoReservationSeeder::class);
        $this->processor = app(MessageProcessor::class);
    }

    /** Exemple de docs/events/ (le fichier exact), éventuellement modifié. */
    private static function example(string $name, array $data = [], array $envelope = []): string
    {
        $json = json_decode(file_get_contents(base_path("../../docs/events/$name.json")), true);
        $json['data'] = array_merge($json['data'], $data);

        return json_encode(array_merge($json, $envelope));
    }

    private static function payment(string $reservationId, string $type = 'RENTAL', ?string $eventId = null): string
    {
        return self::example('PaymentSucceeded', ['reservationId' => $reservationId, 'type' => $type], ['eventId' => $eventId ?? (string) Str::uuid()]);
    }

    private function statusOf(string $id): ReservationStatus
    {
        return Reservation::query()->findOrFail($id)->status;
    }

    // ---------------------------------------------------------------- PaymentSucceeded

    public function test_paiement_rental_confirme_la_reservation_pending(): void
    {
        $outcome = $this->processor->process(self::PAYMENTS, self::payment(DemoIds::R_PENDING), false, 'corr-1');

        $this->assertSame(Outcome::ACK, $outcome);
        $this->assertSame(ReservationStatus::CONFIRMED, $this->statusOf(DemoIds::R_PENDING));
        $this->assertStringContainsString('PENDING -> CONFIRMED', $this->processor->summary);
        $history = Reservation::query()->find(DemoIds::R_PENDING)->statusHistory->last();
        $this->assertNull($history->changed_by); // changement automatique
        $this->assertSame('Paiement RENTAL d76b538e-e367-4bb3-966b-98a4d9dc93a5 reçu', $history->comment);
    }

    public function test_exemple_exact_de_docs_events_sur_r_confirmed_deja_confirmee(): void
    {
        // docs/events/PaymentSucceeded.json vise r-confirmed, déjà CONFIRMED dans les données de démo.
        $this->assertSame(Outcome::ACK, $this->processor->process(self::PAYMENTS, self::example('PaymentSucceeded'), false));
        $this->assertStringContainsString('déjà CONFIRMED', $this->processor->summary);
        $this->assertSame(ReservationStatus::CONFIRMED, $this->statusOf(DemoIds::R_CONFIRMED));
    }

    public function test_paiements_deposit_et_extra_fee_ignores(): void
    {
        foreach (['DEPOSIT', 'EXTRA_FEE'] as $type) {
            $this->assertSame(Outcome::ACK, $this->processor->process(self::PAYMENTS, self::payment(DemoIds::R_PENDING, $type), false));
            $this->assertStringContainsString("ignoré : paiement $type", $this->processor->summary);
        }
        $this->assertSame(ReservationStatus::PENDING, $this->statusOf(DemoIds::R_PENDING));
    }

    public function test_meme_event_id_traite_une_seule_fois(): void
    {
        $eventId = (string) Str::uuid();
        $this->processor->process(self::PAYMENTS, self::payment(DemoIds::R_PENDING, eventId: $eventId), false);
        // Annulée entre-temps : si le doublon était rejoué, il serait traité différemment.
        Reservation::query()->whereKey(DemoIds::R_PENDING)->update(['status' => ReservationStatus::PENDING]);

        $this->assertSame(Outcome::ACK, $this->processor->process(self::PAYMENTS, self::payment(DemoIds::R_PENDING, eventId: $eventId), true));
        $this->assertStringContainsString('déjà traité', $this->processor->summary);
        $this->assertSame(ReservationStatus::PENDING, $this->statusOf(DemoIds::R_PENDING));
        $this->assertSame(1, ProcessedEvent::query()->count());
        $this->assertSame('PENDING -> CONFIRMED', ProcessedEvent::query()->find($eventId)->outcome);
    }

    public function test_paiement_pour_une_reservation_annulee_ignore_avec_avertissement(): void
    {
        Reservation::query()->whereKey(DemoIds::R_PENDING)->update(['status' => ReservationStatus::CANCELLED]);

        $this->assertSame(Outcome::ACK, $this->processor->process(self::PAYMENTS, self::payment(DemoIds::R_PENDING), false));
        $this->assertSame(ReservationStatus::CANCELLED, $this->statusOf(DemoIds::R_PENDING));
    }

    // ---------------------------------------------------------------- ContractClosed

    public function test_contrat_cloture_reservation_ongoing_terminee_et_ml_prevenu(): void
    {
        Reservation::query()->whereKey(DemoIds::R_CONFIRMED)->update(['status' => ReservationStatus::ONGOING]);
        $body = self::example('ContractClosed', ['reservationId' => DemoIds::R_CONFIRMED], ['eventId' => (string) Str::uuid()]);

        $this->assertSame(Outcome::ACK, $this->processor->process(self::CONTRACTS, $body, false));
        $this->assertSame(ReservationStatus::COMPLETED, $this->statusOf(DemoIds::R_CONFIRMED));
        $this->assertSame([['type' => 'ReservationCompleted', 'reservationId' => DemoIds::R_CONFIRMED]], $this->events->published);
    }

    public function test_contrat_cloture_reservation_encore_confirmed_passe_par_ongoing(): void
    {
        $body = self::example('ContractClosed', ['reservationId' => DemoIds::R_CONFIRMED], ['eventId' => (string) Str::uuid()]);

        $this->assertSame(Outcome::ACK, $this->processor->process(self::CONTRACTS, $body, false));
        $this->assertStringContainsString('CONFIRMED -> ONGOING -> COMPLETED', $this->processor->summary);
        $statuses = Reservation::query()->find(DemoIds::R_CONFIRMED)->statusHistory->map(fn ($h) => $h->to_status->value)->all();
        $this->assertSame(['PENDING', 'CONFIRMED', 'ONGOING', 'COMPLETED'], $statuses);
        $this->assertSame(['ReservationCompleted'], $this->events->types());
    }

    public function test_exemple_exact_contract_closed_sur_r_completed_deja_terminee(): void
    {
        $this->assertSame(Outcome::ACK, $this->processor->process(self::CONTRACTS, self::example('ContractClosed'), false));
        $this->assertStringContainsString('déjà COMPLETED', $this->processor->summary);
        $this->assertSame([], $this->events->published);
    }

    public function test_contrat_cloture_sur_reservation_pending_ou_annulee_dlq(): void
    {
        $body = self::example('ContractClosed', ['reservationId' => DemoIds::R_PENDING], ['eventId' => (string) Str::uuid()]);

        $this->assertSame(Outcome::DEAD_LETTER, $this->processor->process(self::CONTRACTS, $body, false));
        $this->assertSame(ReservationStatus::PENDING, $this->statusOf(DemoIds::R_PENDING));
        $this->assertSame(0, ProcessedEvent::query()->count());
    }

    // ---------------------------------------------------------------- messages invalides et erreurs

    public function test_messages_invalides_directement_en_dlq(): void
    {
        $cases = [
            'pas du JSON' => 'bonjour',
            'tableau' => '[1,2]',
            'sans eventId' => self::example('PaymentSucceeded', [], ['eventId' => null]),
            'eventId invalide' => self::example('PaymentSucceeded', [], ['eventId' => '123']),
            'sans data' => self::example('PaymentSucceeded', [], ['data' => null]),
            'mauvais type' => self::example('ContractClosed', [], ['eventId' => (string) Str::uuid()]),
            'réservation inconnue' => self::payment('00000000-0000-4000-8000-000000000000'),
            'reservationId invalide' => self::payment('r-pending'),
            'type de paiement absent' => self::example('PaymentSucceeded', ['type' => null], ['eventId' => (string) Str::uuid()]),
        ];
        foreach ($cases as $case => $body) {
            $this->assertSame(Outcome::DEAD_LETTER, $this->processor->process(self::PAYMENTS, $body, false), $case);
        }
        $this->assertSame(0, ProcessedEvent::query()->count());
    }

    public function test_erreur_passagere_une_remise_en_file_puis_dlq(): void
    {
        // Base en panne pendant le traitement : la table des événements traités n'existe plus.
        DB::statement('DROP TABLE processed_events');
        $body = self::payment(DemoIds::R_PENDING);

        $this->assertSame(Outcome::REQUEUE, $this->processor->process(self::PAYMENTS, $body, false));
        $this->assertSame(Outcome::DEAD_LETTER, $this->processor->process(self::PAYMENTS, $body, true));
        // Rien n'a été appliqué à moitié : la transaction est annulée.
        $this->assertSame(ReservationStatus::PENDING, $this->statusOf(DemoIds::R_PENDING));
    }

    public function test_file_inconnue_dlq(): void
    {
        $this->assertSame(Outcome::DEAD_LETTER, $this->processor->process('autre.file', self::payment(DemoIds::R_PENDING), false));
    }

    public function test_correlation_id_du_message_repris(): void
    {
        $this->processor->process(self::PAYMENTS, self::payment(DemoIds::R_PENDING), false, 'corr-paiement-7');

        $this->assertSame('corr-paiement-7', \App\Http\Middleware\CorrelationId::current());
    }
}
