<?php

namespace Tests\Feature;

use App\Events\AmqpReservationEventPublisher;
use App\Events\ReservationEventPublisher;
use App\Messaging\AmqpPublisher;
use App\Messaging\Envelope;
use App\Messaging\PublishFailed;
use App\Messaging\RabbitConnector;
use App\Models\Reservation;
use App\Support\DemoIds;
use Database\Seeders\DemoReservationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TestJwt;
use Tests\TestCase;

/** Messages publiés : format exact de docs/events/, annulation transactionnelle, fin de location best effort. */
class PublishedEventsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{queue: string, json: array}> */
    private array $sent = [];

    private ?string $failure = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->seed(DemoReservationSeeder::class);

        // Vrai publieur de messages, broker simulé.
        $test = $this;
        $this->app->instance(AmqpPublisher::class, new class(new RabbitConnector(), $test) extends AmqpPublisher {
            public function __construct(RabbitConnector $c, private readonly PublishedEventsTest $test)
            {
                parent::__construct($c);
            }

            public function publish(string $queue, Envelope $envelope): void
            {
                $this->test->record($queue, $envelope);
            }
        });
        $this->app->forgetInstance(ReservationEventPublisher::class);
        $this->app->singleton(ReservationEventPublisher::class, AmqpReservationEventPublisher::class);
    }

    public function record(string $queue, Envelope $envelope): void
    {
        if ($this->failure !== null) {
            throw new PublishFailed($this->failure);
        }
        $this->sent[] = ['queue' => $queue, 'json' => json_decode($envelope->toJson(), true), 'raw' => $envelope->toJson()];
    }

    public function test_reservation_cancelled_au_format_de_docs_events(): void
    {
        $this->api('POST', '/api/reservations/'.DemoIds::R_CONFIRMED.'/cancel', TestJwt::token(DemoIds::CLIENT1, ['CLIENT']), ['reason' => 'Changement de programme'])
            ->assertOk();

        $this->assertCount(1, $this->sent);
        $this->assertSame('payment.reservation_cancelled', $this->sent[0]['queue']);
        $json = $this->sent[0]['json'];
        $expected = json_decode(file_get_contents(base_path('../../docs/events/ReservationCancelled.json')), true);
        // Même contenu que l'exemple de l'équipe, à l'eventId près (unique par annulation).
        $this->assertTrue(\Illuminate\Support\Str::isUuid($json['eventId']));
        unset($json['eventId'], $expected['eventId']);
        $this->assertSame($expected, $json);
    }

    public function test_motif_absent_non_envoye(): void
    {
        $this->api('POST', '/api/reservations/'.DemoIds::R_PENDING.'/cancel', TestJwt::token(DemoIds::CLIENT1, ['CLIENT']))->assertOk();

        $this->assertArrayNotHasKey('reason', $this->sent[0]['json']['data']);
    }

    public function test_rabbitmq_indisponible_l_annulation_est_annulee_503(): void
    {
        $this->failure = 'RabbitMQ injoignable : ReservationCancelled non publié';

        $response = $this->api('POST', '/api/reservations/'.DemoIds::R_PENDING.'/cancel', TestJwt::token(DemoIds::CLIENT1, ['CLIENT']));

        $this->assertError($response, 503, 'SERVICE_UNAVAILABLE', 'Annulation impossible pour le moment');
        $reservation = Reservation::query()->find(DemoIds::R_PENDING);
        $this->assertSame('PENDING', $reservation->status->value);
        $this->assertCount(1, $reservation->statusHistory); // aucune trace de l'annulation
    }

    public function test_reservation_completed_au_format_de_docs_events(): void
    {
        // r-completed revient en ONGOING pour rejouer la fin de location de l'exemple.
        Reservation::query()->whereKey(DemoIds::R_COMPLETED)->update(['status' => 'ONGOING']);

        $this->api('PATCH', '/api/reservations/'.DemoIds::R_COMPLETED.'/status', TestJwt::token(DemoIds::AGENT1, ['AGENT']), ['status' => 'COMPLETED'])
            ->assertOk();

        $this->assertSame('ml.reservation_completed', $this->sent[0]['queue']);
        $json = $this->sent[0]['json'];
        $expected = json_decode(file_get_contents(base_path('../../docs/events/ReservationCompleted.json')), true);
        unset($json['eventId'], $json['occurredAt'], $expected['eventId'], $expected['occurredAt']);
        $this->assertSame($expected, $json);
        // Montant écrit avec 3 décimales, comme partout.
        $this->assertStringContainsString('"totalPrice":640.000', $this->sent[0]['raw']);
    }

    public function test_fin_de_location_maintenue_si_rabbitmq_est_indisponible(): void
    {
        Reservation::query()->whereKey(DemoIds::R_COMPLETED)->update(['status' => 'ONGOING']);
        $this->failure = 'RabbitMQ injoignable';

        $this->api('PATCH', '/api/reservations/'.DemoIds::R_COMPLETED.'/status', TestJwt::token(DemoIds::AGENT1, ['AGENT']), ['status' => 'COMPLETED'])
            ->assertOk()
            ->assertJsonPath('status', 'COMPLETED');
    }
}
