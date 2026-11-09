<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Support\DemoIds;
use Database\Seeders\DemoReservationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestJwt;
use Tests\TestCase;

/** Routes /internal de docs/internal-api.md, avec un token de service (rôle INTERNAL). */
class InternalReservationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoReservationSeeder::class);
    }

    // ---- GET /internal/reservations/{id}

    public function test_detail_au_format_exact_de_internal_api(): void
    {
        $response = $this->getWithToken('/internal/reservations/'.DemoIds::R_CONFIRMED, TestJwt::internal());

        $response->assertOk()->assertExactJson([
            'id' => DemoIds::R_CONFIRMED,
            'userId' => DemoIds::CLIENT1,
            'vehicleId' => DemoIds::V2,
            'pickupAgencyId' => DemoIds::AG_TUNIS,
            'returnAgencyId' => DemoIds::AG_TUNIS,
            'startDate' => '2026-11-20',
            'endDate' => '2026-11-25',
            'status' => 'CONFIRMED',
            'totalPrice' => 450.0,
            'depositAmount' => 500.0,
            'currency' => 'TND',
        ]);
        // Montants écrits en nombres décimaux, comme les attend payment-service.
        $this->assertStringContainsString('"totalPrice":450.0,"depositAmount":500.0', $response->getContent());
    }

    public function test_montants_de_demo_alignes_sur_les_paiements(): void
    {
        $expected = [
            DemoIds::R_PENDING => [270.0, 500.0, 'PENDING'],
            DemoIds::R_CONFIRMED => [450.0, 500.0, 'CONFIRMED'],
            DemoIds::R_COMPLETED => [640.0, 1000.0, 'COMPLETED'],
        ];
        foreach ($expected as $id => [$total, $deposit, $status]) {
            $this->getWithToken("/internal/reservations/$id", TestJwt::internal())
                ->assertOk()
                ->assertJsonPath('totalPrice', $total)
                ->assertJsonPath('depositAmount', $deposit)
                ->assertJsonPath('status', $status);
        }
    }

    public function test_uuid_en_majuscules_accepte(): void
    {
        $this->getWithToken('/internal/reservations/'.strtoupper(DemoIds::R_PENDING), TestJwt::internal())
            ->assertOk()
            ->assertJsonPath('id', DemoIds::R_PENDING);
    }

    public function test_reservation_inconnue_404_au_format_commun(): void
    {
        $response = $this->getWithToken('/internal/reservations/00000000-0000-4000-8000-000000000000', TestJwt::internal());

        $response->assertExactJson(['status' => 404, 'error' => 'NOT_FOUND', 'message' => 'Réservation introuvable']);
    }

    public function test_identifiant_qui_n_est_pas_un_uuid_404(): void
    {
        $this->assertError($this->getWithToken('/internal/reservations/abc', TestJwt::internal()), 404, 'NOT_FOUND', 'Réservation introuvable');
    }

    // ---- GET /internal/reservations?userId=

    public function test_liste_paginee_d_un_utilisateur(): void
    {
        $response = $this->getWithToken('/internal/reservations?userId='.DemoIds::CLIENT1, TestJwt::internal());

        $response->assertOk()
            ->assertJsonStructure(['items', 'page', 'size', 'total'])
            ->assertJsonPath('page', 0)
            ->assertJsonPath('size', 20)
            ->assertJsonPath('total', 2);
        // Plus récentes d'abord (date de début décroissante), même format que le détail.
        $this->assertSame([DemoIds::R_PENDING, DemoIds::R_CONFIRMED], array_column($response->json('items'), 'id'));
        $this->assertSame(
            ['id', 'userId', 'vehicleId', 'pickupAgencyId', 'returnAgencyId', 'startDate', 'endDate', 'status', 'totalPrice', 'depositAmount', 'currency'],
            array_keys($response->json('items.0')),
        );
    }

    public function test_pagination_page_et_size(): void
    {
        $response = $this->getWithToken('/internal/reservations?userId='.DemoIds::CLIENT1.'&page=1&size=1', TestJwt::internal());

        $response->assertOk()
            ->assertJsonPath('page', 1)
            ->assertJsonPath('size', 1)
            ->assertJsonPath('total', 2)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', DemoIds::R_CONFIRMED);

        $this->getWithToken('/internal/reservations?userId='.DemoIds::CLIENT1.'&page=5', TestJwt::internal())
            ->assertOk()
            ->assertJsonPath('items', [])
            ->assertJsonPath('total', 2);
    }

    public function test_utilisateur_sans_reservation_liste_vide(): void
    {
        $this->getWithToken('/internal/reservations?userId='.DemoIds::AGENT1, TestJwt::internal())
            ->assertOk()
            ->assertExactJson(['items' => [], 'page' => 0, 'size' => 20, 'total' => 0]);
    }

    public function test_parametres_invalides_400(): void
    {
        $this->assertError($this->getWithToken('/internal/reservations', TestJwt::internal()), 400, 'BAD_REQUEST', null, ['userId']);
        $this->assertError($this->getWithToken('/internal/reservations?userId=abc', TestJwt::internal()), 400, 'BAD_REQUEST', null, ['userId']);
        $this->assertError(
            $this->getWithToken('/internal/reservations?userId='.DemoIds::CLIENT1.'&page=-1&size=500', TestJwt::internal()),
            400, 'BAD_REQUEST', null, ['page', 'size'],
        );
    }

    // ---- GET /internal/reservations/active?vehicleId=

    public function test_vehicule_avec_reservation_pending_actif(): void
    {
        $this->getWithToken('/internal/reservations/active?vehicleId='.DemoIds::V1, TestJwt::internal())
            ->assertOk()
            ->assertExactJson(['vehicleId' => DemoIds::V1, 'active' => true, 'reservationIds' => [DemoIds::R_PENDING]]);
    }

    public function test_vehicule_avec_reservation_confirmed_actif(): void
    {
        $this->getWithToken('/internal/reservations/active?vehicleId='.DemoIds::V2, TestJwt::internal())
            ->assertOk()
            ->assertExactJson(['vehicleId' => DemoIds::V2, 'active' => true, 'reservationIds' => [DemoIds::R_CONFIRMED]]);
    }

    public function test_reservation_ongoing_compte_comme_active(): void
    {
        Reservation::query()->whereKey(DemoIds::R_CONFIRMED)->update(['status' => ReservationStatus::ONGOING]);

        $this->getWithToken('/internal/reservations/active?vehicleId='.DemoIds::V2, TestJwt::internal())
            ->assertJsonPath('active', true);
    }

    public function test_reservations_completed_ou_cancelled_ne_comptent_pas(): void
    {
        // v3 n'a que r-completed.
        $this->getWithToken('/internal/reservations/active?vehicleId='.DemoIds::V3, TestJwt::internal())
            ->assertOk()
            ->assertExactJson(['vehicleId' => DemoIds::V3, 'active' => false, 'reservationIds' => []]);

        Reservation::query()->whereKey(DemoIds::R_PENDING)->update(['status' => ReservationStatus::CANCELLED]);
        $this->getWithToken('/internal/reservations/active?vehicleId='.DemoIds::V1, TestJwt::internal())
            ->assertJsonPath('active', false);
    }

    public function test_vehicule_inconnu_inactif(): void
    {
        $this->getWithToken('/internal/reservations/active?vehicleId='.DemoIds::V5, TestJwt::internal())
            ->assertOk()
            ->assertJsonPath('active', false);
    }

    public function test_vehicle_id_manquant_ou_invalide_400(): void
    {
        $this->assertError($this->getWithToken('/internal/reservations/active', TestJwt::internal()), 400, 'BAD_REQUEST', null, ['vehicleId']);
        $this->assertError($this->getWithToken('/internal/reservations/active?vehicleId=v1', TestJwt::internal()), 400, 'BAD_REQUEST', null, ['vehicleId']);
    }

    // ---- Protection : rôle INTERNAL

    /** @return iterable<string, array{string}> */
    public static function internalRoutes(): iterable
    {
        yield 'detail' => ['/internal/reservations/'.DemoIds::R_PENDING];
        yield 'par utilisateur' => ['/internal/reservations?userId='.DemoIds::CLIENT1];
        yield 'actives' => ['/internal/reservations/active?vehicleId='.DemoIds::V1];
    }

    #[DataProvider('internalRoutes')]
    public function test_sans_token_401(string $uri): void
    {
        $response = $this->getWithToken($uri, null);

        $this->assertError($response, 401, 'UNAUTHORIZED', 'Token absent');
        $response->assertHeader('WWW-Authenticate', 'Bearer');
    }

    #[DataProvider('internalRoutes')]
    public function test_token_utilisateur_sans_role_internal_403(string $uri): void
    {
        foreach (['CLIENT', 'AGENT', 'ADMIN'] as $role) {
            $this->assertError($this->getWithToken($uri, TestJwt::token(DemoIds::ADMIN1, [$role])), 403, 'FORBIDDEN', 'INTERNAL');
        }
    }

    #[DataProvider('internalRoutes')]
    public function test_token_de_service_internal_200(string $uri): void
    {
        $this->getWithToken($uri, TestJwt::internal())->assertOk();
    }

    public function test_les_parametres_ne_sont_valides_qu_apres_le_token(): void
    {
        // Sans token, même une requête invalide reçoit 401, pas 400.
        $this->assertError($this->getWithToken('/internal/reservations', null), 401, 'UNAUTHORIZED');
    }
}
