<?php

namespace Tests\Feature;

use App\Eureka\ServiceRegistry;
use App\Integration\Demo\DemoUserLookup;
use App\Integration\Demo\DemoVehicleLookup;
use App\Integration\Http\HttpUserLookup;
use App\Integration\Http\HttpVehicleLookup;
use App\Integration\UserLookup;
use App\Integration\VehicleLookup;
use App\Support\DemoIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeServiceRegistry;
use Tests\Support\TestJwt;
use Tests\TestCase;

/**
 * LOOKUP_MODE=eureka : vrais appels HTTP (simulés par Http::fake) vers RENTAL-USER et RENTAL-VEHICLE,
 * adresse trouvée dans Eureka, token client credentials de Keycloak, traduction 404 / 503 / 502.
 */
class EurekaLookupTest extends TestCase
{
    use RefreshDatabase;

    private const USERS = 'http://host.docker.internal:8101';
    private const VEHICLES = 'http://host.docker.internal:8102';
    private const TOKEN_URL = 'http://localhost:8180/realms/location-voitures/protocol/openid-connect/token';

    private FakeServiceRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09 10:00:00');
        config(['reservation.lookup_mode' => 'eureka', 'keycloak.client_secret' => 'secret-de-test']);
        $this->registry = new FakeServiceRegistry();
        $this->registry->instances = ['RENTAL-USER' => self::USERS, 'RENTAL-VEHICLE' => self::VEHICLES];
        $this->app->instance(ServiceRegistry::class, $this->registry);
    }

    /** Réponses par défaut : celles des vrais services pour les données de démo. */
    private function fakeServices(array $overrides = []): void
    {
        Http::fake($overrides + [
            self::TOKEN_URL => Http::response(['access_token' => 'jeton-de-service', 'expires_in' => 300, 'token_type' => 'Bearer']),
            self::USERS.'/internal/users/*' => function (Request $r) {
                $payload = DemoUserLookup::payloads()[basename($r->url())] ?? null;

                return $payload ? Http::response($payload) : Http::response(['status' => 404, 'error' => 'NOT_FOUND', 'message' => 'Utilisateur introuvable'], 404);
            },
            self::VEHICLES.'/internal/vehicles/*' => function (Request $r) {
                $payload = DemoVehicleLookup::payloads()[basename($r->url())] ?? null;

                return $payload ? Http::response($payload) : Http::response(['status' => 404, 'error' => 'NOT_FOUND', 'message' => 'Véhicule introuvable'], 404);
            },
        ]);
    }

    private function book(string $userToken, array $override = []): \Illuminate\Testing\TestResponse
    {
        return $this->api('POST', '/api/reservations', $userToken, $override + [
            'vehicleId' => DemoIds::V4, 'startDate' => '2026-12-01', 'endDate' => '2026-12-04',
        ]);
    }

    private static function client(string $id = DemoIds::CLIENT1): string
    {
        return TestJwt::token($id, ['CLIENT']);
    }

    public function test_le_mode_choisit_l_implementation(): void
    {
        $this->assertInstanceOf(HttpUserLookup::class, app(UserLookup::class));
        $this->assertInstanceOf(HttpVehicleLookup::class, app(VehicleLookup::class));

        config(['reservation.lookup_mode' => 'demo']);
        $this->assertInstanceOf(DemoUserLookup::class, app(UserLookup::class));

        config(['reservation.lookup_mode' => 'autre']);
        $this->expectException(\LogicException::class);
        app(UserLookup::class);
    }

    public function test_creation_avec_les_vrais_appels(): void
    {
        $this->fakeServices();

        $this->book(self::client(), ['startDate' => '2026-12-01', 'endDate' => '2026-12-03'])
            ->assertCreated()
            ->assertJsonPath('totalPrice', 320.0)
            ->assertJsonPath('depositAmount', 1000.0);

        // Adresses trouvées dans Eureka, token de service, X-Correlation-Id retransmis.
        $this->assertSame(['resolve:RENTAL-USER', 'resolve:RENTAL-VEHICLE'], $this->registry->calls);
        Http::assertSent(fn (Request $r) => $r->url() === self::USERS.'/internal/users/'.DemoIds::CLIENT1
            && $r->hasHeader('Authorization', 'Bearer jeton-de-service')
            && $r->hasHeader('X-Correlation-Id'));
        Http::assertSent(fn (Request $r) => $r->url() === self::TOKEN_URL
            && $r['grant_type'] === 'client_credentials' && $r['client_id'] === 'reservation-service' && $r['client_secret'] === 'secret-de-test');
    }

    public function test_correlation_id_recu_retransmis(): void
    {
        $this->fakeServices();

        $this->json('POST', '/api/reservations', ['vehicleId' => DemoIds::V4, 'startDate' => '2026-12-01', 'endDate' => '2026-12-04'], [
            'Authorization' => 'Bearer '.self::client(), 'X-Correlation-Id' => 'demo-42',
        ])->assertCreated()->assertHeader('X-Correlation-Id', 'demo-42');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/internal/vehicles/') && $r->hasHeader('X-Correlation-Id', 'demo-42'));
    }

    public function test_token_de_service_en_cache(): void
    {
        $this->fakeServices();

        $this->book(self::client())->assertCreated();
        $this->book(self::client(), ['startDate' => '2026-12-10', 'endDate' => '2026-12-12'])->assertCreated();

        Http::assertSentCount(5); // 1 token + 2 x (utilisateur + véhicule)
    }

    public function test_permis_expire_de_client2_422(): void
    {
        $this->fakeServices();

        $this->assertError($this->book(self::client(DemoIds::CLIENT2)), 422, 'UNPROCESSABLE_ENTITY', 'expire le 2026-06-30');
    }

    public function test_objet_distant_introuvable_404(): void
    {
        $this->fakeServices();

        $this->assertError($this->book(self::client(), ['vehicleId' => '00000000-0000-4000-8000-000000000000']), 404, 'NOT_FOUND', 'Véhicule introuvable');
    }

    public function test_service_absent_d_eureka_503(): void
    {
        $this->fakeServices();
        unset($this->registry->instances['RENTAL-USER']);

        $this->assertError($this->book(self::client()), 503, 'SERVICE_UNAVAILABLE', 'Service RENTAL-USER indisponible');
    }

    public function test_service_injoignable_503(): void
    {
        $this->fakeServices([self::VEHICLES.'/*' => fn () => throw new ConnectionException('Connection refused')]);

        $response = $this->book(self::client());

        $this->assertError($response, 503, 'SERVICE_UNAVAILABLE', 'Service RENTAL-VEHICLE injoignable');
        $this->assertStringNotContainsString('8102', $response->json('message')); // adresse seulement dans le log
    }

    public function test_service_qui_repond_503_ou_504_503(): void
    {
        $this->fakeServices([self::USERS.'/*' => Http::response('', 504)]);

        $this->assertError($this->book(self::client()), 503, 'SERVICE_UNAVAILABLE', 'HTTP 504');
    }

    public function test_reponses_illisibles_502(): void
    {
        $cases = [
            'JSON invalide' => Http::response('<html>erreur</html>', 200),
            'objet JSON attendu' => Http::response([['id' => DemoIds::V4]], 200),
            'category' => Http::response(['id' => DemoIds::V4, 'status' => 'AVAILABLE', 'agencyId' => DemoIds::AG_TUNIS], 200),
            'dailyRate' => Http::response(['id' => DemoIds::V4, 'status' => 'AVAILABLE', 'agencyId' => DemoIds::AG_TUNIS,
                'category' => ['id' => DemoIds::CAT_SUV, 'name' => 'SUV', 'dailyRate' => 'cher', 'deposit' => 1000]], 200),
            'HTTP 500' => Http::response('', 500),
        ];
        foreach ($cases as $message => $vehicleResponse) {
            Http::swap(new \Illuminate\Http\Client\Factory());
            $this->fakeServices([self::VEHICLES.'/*' => $vehicleResponse]);
            $this->assertError($this->book(self::client()), 502, 'BAD_GATEWAY', $message);
        }
    }

    public function test_utilisateur_sans_champ_license_502(): void
    {
        $payload = DemoUserLookup::payloads()[DemoIds::CLIENT1];
        unset($payload['license']);
        $this->fakeServices([self::USERS.'/*' => Http::response($payload)]);

        $this->assertError($this->book(self::client()), 502, 'BAD_GATEWAY', 'Réponse illisible du service RENTAL-USER');
    }

    public function test_montants_en_chaine_acceptes(): void
    {
        $payload = DemoVehicleLookup::payloads()[DemoIds::V4];
        $payload['category']['dailyRate'] = '160.000';
        $this->fakeServices([self::VEHICLES.'/*' => Http::response($payload)]);

        $this->book(self::client())->assertCreated()->assertJsonPath('totalPrice', 480.0);
    }

    public function test_token_refuse_nouvel_essai_avec_un_token_neuf(): void
    {
        $tokens = 0;
        $this->fakeServices([
            self::TOKEN_URL => function () use (&$tokens) {
                $tokens++;

                return Http::response(['access_token' => "jeton-$tokens", 'expires_in' => 300]);
            },
            self::USERS.'/*' => fn (Request $r) => $r->hasHeader('Authorization', 'Bearer jeton-1')
                ? Http::response(['status' => 401], 401)
                : Http::response(DemoUserLookup::payloads()[DemoIds::CLIENT1]),
        ]);

        $this->book(self::client())->assertCreated();
        $this->assertSame(2, $tokens);
    }

    public function test_token_toujours_refuse_502(): void
    {
        $this->fakeServices([self::USERS.'/*' => Http::response(['status' => 403], 403)]);

        $this->assertError($this->book(self::client()), 502, 'BAD_GATEWAY', 'refusé le token de service');
    }

    public function test_keycloak_injoignable_ou_secret_absent_503(): void
    {
        $this->fakeServices([self::TOKEN_URL => fn () => throw new ConnectionException('Connection refused')]);
        $this->assertError($this->book(self::client()), 503, 'SERVICE_UNAVAILABLE', 'Keycloak injoignable');

        config(['keycloak.client_secret' => '']);
        $this->assertError($this->book(self::client()), 503, 'SERVICE_UNAVAILABLE', 'secret du client Keycloak non configuré');
    }

    public function test_client_refuse_par_keycloak_503(): void
    {
        $this->fakeServices([self::TOKEN_URL => Http::response(['error' => 'unauthorized_client'], 401)]);

        $this->assertError($this->book(self::client()), 503, 'SERVICE_UNAVAILABLE', 'refusé par Keycloak');
    }

    // ---------------------------------------------------------------- prix conseillé (RENTAL-ML)

    private const ML = 'http://host.docker.internal:8107';

    private function quote(): \Illuminate\Testing\TestResponse
    {
        return $this->api('POST', '/api/reservations/quote', self::client(), [
            'vehicleId' => DemoIds::V4, 'startDate' => '2026-12-01', 'endDate' => '2026-12-04',
        ]);
    }

    public function test_prix_conseille_par_le_ml(): void
    {
        $this->registry->instances['RENTAL-ML'] = self::ML;
        $this->fakeServices([self::ML.'/internal/ml/price-predictions' => Http::response(
            ['dailyPrice' => 152.5, 'lowerBound' => 140.0, 'upperBound' => 171.25, 'modelVersion' => 'v1-2026-11'],
        )]);

        $response = $this->quote()->assertOk()
            ->assertJsonPath('totalPrice', 480.0) // le prix facturé reste le prix fixe
            ->assertJsonPath('suggestedPrice.modelVersion', 'v1-2026-11');
        $this->assertStringContainsString(
            '"suggestedPrice":{"dailyPrice":152.500,"totalPrice":457.500,"lowerBound":140.000,"upperBound":171.250,"modelVersion":"v1-2026-11"}',
            $response->getContent(),
        );
        Http::assertSent(fn (Request $r) => $r->url() === self::ML.'/internal/ml/price-predictions'
            && $r->method() === 'POST'
            && $r->data() === ['categoryId' => DemoIds::CAT_SUV, 'agencyId' => DemoIds::AG_TUNIS, 'startDate' => '2026-12-01', 'endDate' => '2026-12-04']
            && $r->hasHeader('Authorization', 'Bearer jeton-de-service'));
    }

    public function test_prix_conseille_avec_model_version_entier(): void
    {
        // Contrat de ml-service (docs/openapi/ml-service.yaml) : modelVersion est un entier.
        $this->registry->instances['RENTAL-ML'] = self::ML;
        $this->fakeServices([self::ML.'/internal/ml/price-predictions' => Http::response(
            ['dailyPrice' => 149.236, 'lowerBound' => 135.834, 'upperBound' => 162.383, 'currency' => 'TND', 'modelVersion' => 1],
        )]);

        $response = $this->quote()->assertOk()
            ->assertJsonPath('totalPrice', 480.0) // le prix facturé reste le prix fixe
            ->assertJsonPath('suggestedPrice.modelVersion', '1');
        $this->assertStringContainsString(
            '"suggestedPrice":{"dailyPrice":149.236,"totalPrice":447.708,"lowerBound":135.834,"upperBound":162.383,"modelVersion":"1"}',
            $response->getContent(),
        );
    }

    public function test_ml_indisponible_repli_sur_le_prix_fixe(): void
    {
        // Absent d'Eureka.
        $this->fakeServices();
        $this->quote()->assertOk()->assertJsonPath('suggestedPrice', null)->assertJsonPath('totalPrice', 480.0);

        // Présent mais en erreur, ou réponse illisible.
        $this->registry->instances['RENTAL-ML'] = self::ML;
        foreach ([Http::response('', 500), Http::response(['dailyPrice' => 'cher'], 200), fn () => throw new ConnectionException('refusé')] as $mlResponse) {
            Http::swap(new \Illuminate\Http\Client\Factory());
            $this->fakeServices([self::ML.'/*' => $mlResponse]);
            $this->quote()->assertOk()->assertJsonPath('suggestedPrice', null);
        }
    }
}
