<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Support\DemoIds;
use Database\Seeders\DemoReservationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TestJwt;
use Tests\TestCase;

/** API publique /api/reservations en LOOKUP_MODE=demo (utilisateurs et véhicules de docs/demo-ids.md). */
class ReservationApiTest extends TestCase
{
    use RefreshDatabase;

    private const UNKNOWN = '00000000-0000-4000-8000-000000000000';

    protected function setUp(): void
    {
        parent::setUp();
        // Lundi 9 novembre 2026, 10 h UTC (11 h à Tunis).
        Carbon::setTestNow('2026-11-09 10:00:00');
        $this->seed(DemoReservationSeeder::class);
    }

    private static function client1(): string
    {
        return TestJwt::token(DemoIds::CLIENT1, ['CLIENT']);
    }

    private static function client2(): string
    {
        return TestJwt::token(DemoIds::CLIENT2, ['CLIENT']);
    }

    private static function agent(): string
    {
        return TestJwt::token(DemoIds::AGENT1, ['AGENT']);
    }

    private static function admin(): string
    {
        return TestJwt::token(DemoIds::ADMIN1, ['ADMIN']);
    }

    /** Réservation de v4 (SUV, Tunis) pour client1, sans conflit avec les données de démo. */
    private function book(array $override = [], ?string $token = null): \Illuminate\Testing\TestResponse
    {
        return $this->api('POST', '/api/reservations', $token ?? self::client1(), $override + [
            'vehicleId' => DemoIds::V4,
            'startDate' => '2026-12-01',
            'endDate' => '2026-12-04',
        ]);
    }

    // ---------------------------------------------------------------- création

    public function test_creation_201_location_prix_et_caution_de_la_categorie(): void
    {
        $response = $this->book(['returnAgencyId' => DemoIds::AG_SOUSSE]);

        $response->assertCreated()
            ->assertHeader('Location', '/api/reservations/'.$response->json('id'))
            ->assertJsonPath('userId', DemoIds::CLIENT1)
            ->assertJsonPath('vehicleId', DemoIds::V4)
            ->assertJsonPath('categoryId', DemoIds::CAT_SUV)
            ->assertJsonPath('pickupAgencyId', DemoIds::AG_TUNIS) // agence du véhicule
            ->assertJsonPath('returnAgencyId', DemoIds::AG_SOUSSE)
            ->assertJsonPath('startDate', '2026-12-01')
            ->assertJsonPath('endDate', '2026-12-04')
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('allowedTransitions', ['CONFIRMED', 'CANCELLED'])
            ->assertJsonPath('currency', 'TND')
            ->assertJsonPath('createdAt', '2026-11-09T10:00:00Z')
            ->assertJsonPath('statusHistory.0.fromStatus', null)
            ->assertJsonPath('statusHistory.0.toStatus', 'PENDING')
            ->assertJsonPath('statusHistory.0.changedBy', DemoIds::CLIENT1);
        // 3 jours x 160.000 (SUV) ; caution SUV 1000.000 ; écrits avec 3 décimales.
        $this->assertStringContainsString('"totalPrice":480.000,"depositAmount":1000.000', $response->getContent());
        $this->assertTrue(Reservation::query()->whereKey($response->json('id'))->exists());
        // UUID v4 (docs/conventions.md), pas le v7 par défaut de Laravel.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $response->json('id'));
        $this->assertSame(4, \Ramsey\Uuid\Uuid::fromString(\App\Models\ReservationStatusHistory::query()->value('id'))->getFields()->getVersion());
    }

    public function test_prix_identiques_aux_reservations_de_demo(): void
    {
        // Mêmes règles que r-pending : v1, 3 jours x 90.000 = 270.000, caution 500.000.
        Reservation::query()->whereKey(DemoIds::R_PENDING)->delete();

        $this->book(['vehicleId' => DemoIds::V1, 'startDate' => '2026-12-10', 'endDate' => '2026-12-13'])
            ->assertCreated()
            ->assertJsonPath('totalPrice', 270.0)
            ->assertJsonPath('depositAmount', 500.0);
    }

    public function test_agence_de_retour_par_defaut_celle_de_depart(): void
    {
        $this->book()->assertCreated()->assertJsonPath('returnAgencyId', DemoIds::AG_TUNIS);
    }

    public function test_agence_de_depart_differente_de_celle_du_vehicule_422(): void
    {
        $this->assertError($this->book(['pickupAgencyId' => DemoIds::AG_SOUSSE]), 422, 'UNPROCESSABLE_ENTITY', 'agence de départ', ['pickupAgencyId']);
        $this->book(['pickupAgencyId' => DemoIds::AG_TUNIS])->assertCreated();
    }

    public function test_agent_reserve_pour_un_client(): void
    {
        $this->book(['userId' => DemoIds::CLIENT1], self::agent())
            ->assertCreated()
            ->assertJsonPath('userId', DemoIds::CLIENT1)
            ->assertJsonPath('statusHistory.0.changedBy', DemoIds::AGENT1);
    }

    public function test_agent_sans_user_id_400(): void
    {
        $this->assertError($this->book([], self::agent()), 400, 'BAD_REQUEST', null, ['userId']);
    }

    public function test_client_qui_reserve_pour_un_autre_403(): void
    {
        $this->assertError($this->book(['userId' => DemoIds::CLIENT2]), 403, 'FORBIDDEN', 'pour lui-même');
        $this->book(['userId' => DemoIds::CLIENT1])->assertCreated();
    }

    // Règle 6 : dates (400)

    public function test_dates_invalides_400(): void
    {
        $this->assertError($this->book(['startDate' => '2026-11-08']), 400, 'BAD_REQUEST', null, ['startDate']);
        $this->assertError($this->book(['endDate' => '2026-12-01']), 400, 'BAD_REQUEST', null, ['endDate']);
        $this->assertError($this->book(['endDate' => '2026-11-30']), 400, 'BAD_REQUEST', null, ['endDate']);
        $this->assertError($this->book(['startDate' => '01/12/2026']), 400, 'BAD_REQUEST', null, ['startDate']);
        $this->assertError($this->book(['startDate' => '2026-02-30']), 400, 'BAD_REQUEST', null, ['startDate']);
        $this->assertError($this->api('POST', '/api/reservations', self::client1(), []), 400, 'BAD_REQUEST', null, ['vehicleId', 'startDate', 'endDate']);
        $this->assertError($this->book(['vehicleId' => 'v4']), 400, 'BAD_REQUEST', null, ['vehicleId']);
    }

    public function test_depart_aujourd_hui_accepte_a_l_heure_de_tunis(): void
    {
        // 23 h 30 UTC le 9 = 0 h 30 le 10 à Tunis : « aujourd'hui » est le 10.
        Carbon::setTestNow('2026-11-09 23:30:00');
        $this->assertError($this->book(['startDate' => '2026-11-09', 'endDate' => '2026-11-11']), 400, 'BAD_REQUEST', '', ['startDate']);
        $this->book(['startDate' => '2026-11-10', 'endDate' => '2026-11-11'])->assertCreated()->assertJsonPath('totalPrice', 160.0);
    }

    // Règles 1 et 2 : client et permis

    public function test_client_inconnu_404(): void
    {
        $this->assertError($this->book(['userId' => self::UNKNOWN], self::agent()), 404, 'NOT_FOUND', 'Client introuvable');
    }

    public function test_permis_expire_422_client2(): void
    {
        $response = $this->book([], self::client2());

        $this->assertError($response, 422, 'UNPROCESSABLE_ENTITY', 'expire le 2026-06-30', ['endDate']);
        $this->assertSame(3, Reservation::count());
    }

    public function test_permis_absent_422(): void
    {
        // agent1 n'a pas de permis.
        $this->assertError($this->book(['userId' => DemoIds::AGENT1], self::admin()), 422, 'UNPROCESSABLE_ENTITY', 'pas de permis', ['license']);
    }

    public function test_permis_valide_jusqu_au_jour_du_retour_inclus(): void
    {
        // Permis de client1 valide jusqu'au 2030-03-15.
        $this->book(['startDate' => '2030-03-10', 'endDate' => '2030-03-15'])->assertCreated();
        $this->assertError($this->book(['startDate' => '2030-03-12', 'endDate' => '2030-03-16']), 422, 'UNPROCESSABLE_ENTITY', 'expire le 2030-03-15');
    }

    // Règles 3 et 4 : véhicule

    public function test_vehicule_inconnu_404(): void
    {
        $this->assertError($this->book(['vehicleId' => self::UNKNOWN]), 404, 'NOT_FOUND', 'Véhicule introuvable');
    }

    public function test_vehicule_en_maintenance_409(): void
    {
        $this->assertError($this->book(['vehicleId' => DemoIds::V6]), 409, 'CONFLICT', 'MAINTENANCE', ['vehicleId']);
    }

    public function test_le_client_est_controle_avant_le_vehicule(): void
    {
        // client2 (permis expiré) sur un véhicule inconnu : 422, pas 404.
        $this->assertError($this->book(['vehicleId' => self::UNKNOWN], self::client2()), 422, 'UNPROCESSABLE_ENTITY');
    }

    // Règle 5 : chevauchement

    public function test_chevauchement_avec_une_reservation_pending_409(): void
    {
        // r-pending : v1 du 2026-12-10 au 2026-12-13.
        $response = $this->book(['vehicleId' => DemoIds::V1, 'startDate' => '2026-12-12', 'endDate' => '2026-12-15']);

        $this->assertError($response, 409, 'CONFLICT', 'déjà réservé sur ces dates');
        $response->assertJsonPath('details.0.message', 'Déjà réservé du 2026-12-10 au 2026-12-13');
    }

    public function test_chevauchement_avec_une_reservation_confirmed_ou_ongoing_409(): void
    {
        // r-confirmed : v2 du 2026-11-20 au 2026-11-25 ; période qui l'englobe.
        $this->assertError($this->book(['vehicleId' => DemoIds::V2, 'startDate' => '2026-11-19', 'endDate' => '2026-11-26']), 409, 'CONFLICT');

        Reservation::query()->whereKey(DemoIds::R_CONFIRMED)->update(['status' => ReservationStatus::ONGOING]);
        $this->assertError($this->book(['vehicleId' => DemoIds::V2, 'startDate' => '2026-11-21', 'endDate' => '2026-11-22']), 409, 'CONFLICT');
    }

    public function test_retour_le_jour_du_depart_suivant_accepte(): void
    {
        // r-confirmed se termine le 25 : un départ le 25 et un retour le 20 sont possibles.
        $this->book(['vehicleId' => DemoIds::V2, 'startDate' => '2026-11-25', 'endDate' => '2026-11-27'])->assertCreated();
        $this->book(['vehicleId' => DemoIds::V2, 'startDate' => '2026-11-18', 'endDate' => '2026-11-20'])->assertCreated();
    }

    public function test_reservations_annulees_ou_terminees_ne_bloquent_pas(): void
    {
        Reservation::query()->whereKey(DemoIds::R_PENDING)->update(['status' => ReservationStatus::CANCELLED]);
        $this->book(['vehicleId' => DemoIds::V1, 'startDate' => '2026-12-10', 'endDate' => '2026-12-13'])->assertCreated();
    }

    public function test_la_nouvelle_reservation_pending_bloque_la_suivante(): void
    {
        $this->book()->assertCreated();
        $this->assertError($this->book(['userId' => DemoIds::CLIENT1], self::agent()), 409, 'CONFLICT');
    }

    // ---------------------------------------------------------------- devis

    public function test_devis_sans_creation_prix_fixe_sans_ml_en_mode_demo(): void
    {
        $response = $this->api('POST', '/api/reservations/quote', self::client1(), [
            'vehicleId' => DemoIds::V4, 'startDate' => '2026-12-01', 'endDate' => '2026-12-04', 'returnAgencyId' => DemoIds::AG_SOUSSE,
        ]);

        $response->assertOk()->assertExactJson([
            'vehicleId' => DemoIds::V4,
            'categoryId' => DemoIds::CAT_SUV,
            'categoryName' => 'SUV',
            'pickupAgencyId' => DemoIds::AG_TUNIS,
            'returnAgencyId' => DemoIds::AG_SOUSSE,
            'startDate' => '2026-12-01',
            'endDate' => '2026-12-04',
            'days' => 3,
            'dailyRate' => 160.0,
            'totalPrice' => 480.0,
            'depositAmount' => 1000.0,
            'currency' => 'TND',
            'suggestedPrice' => null,
        ]);
        $this->assertStringContainsString('"dailyRate":160.000,"totalPrice":480.000,"depositAmount":1000.000', $response->getContent());
        $this->assertSame(3, Reservation::count());
    }

    public function test_devis_applique_les_regles_de_creation(): void
    {
        $this->assertError($this->api('POST', '/api/reservations/quote', self::client2(), [
            'vehicleId' => DemoIds::V4, 'startDate' => '2026-12-01', 'endDate' => '2026-12-04',
        ]), 422, 'UNPROCESSABLE_ENTITY', 'expire le 2026-06-30');
        $this->assertError($this->api('POST', '/api/reservations/quote', self::client1(), [
            'vehicleId' => DemoIds::V1, 'startDate' => '2026-12-12', 'endDate' => '2026-12-14',
        ]), 409, 'CONFLICT', 'déjà réservé');
        $this->assertError($this->api('POST', '/api/reservations/quote', self::client1(), [
            'vehicleId' => DemoIds::V6, 'startDate' => '2026-12-01', 'endDate' => '2026-12-02',
        ]), 409, 'CONFLICT', 'MAINTENANCE');
        $this->assertError($this->api('POST', '/api/reservations/quote', self::client1(), ['vehicleId' => DemoIds::V4]), 400, 'BAD_REQUEST', null, ['startDate', 'endDate']);
        $this->assertError($this->api('POST', '/api/reservations/quote', null, []), 401, 'UNAUTHORIZED');
    }

    // ---------------------------------------------------------------- lecture

    public function test_liste_d_un_client_seulement_ses_reservations(): void
    {
        $response = $this->api('GET', '/api/reservations', self::client1());

        $response->assertOk()
            ->assertJsonPath('page', 0)
            ->assertJsonPath('size', 20)
            ->assertJsonPath('total', 2);
        $this->assertSame([DemoIds::R_PENDING, DemoIds::R_CONFIRMED], array_column($response->json('items'), 'id'));
        $this->assertArrayNotHasKey('statusHistory', $response->json('items.0'));

        $this->api('GET', '/api/reservations', self::client2())->assertJsonPath('total', 1)->assertJsonPath('items.0.id', DemoIds::R_COMPLETED);
    }

    public function test_client_qui_filtre_sur_un_autre_client_403(): void
    {
        $this->assertError($this->api('GET', '/api/reservations?userId='.DemoIds::CLIENT2, self::client1()), 403, 'FORBIDDEN');
        $this->api('GET', '/api/reservations?userId='.DemoIds::CLIENT1, self::client1())->assertOk()->assertJsonPath('total', 2);
    }

    public function test_agent_voit_tout_et_filtre(): void
    {
        $this->api('GET', '/api/reservations', self::agent())->assertJsonPath('total', 3);
        $this->api('GET', '/api/reservations?status=COMPLETED', self::agent())->assertJsonPath('total', 1)->assertJsonPath('items.0.id', DemoIds::R_COMPLETED);
        $this->api('GET', '/api/reservations?vehicleId='.DemoIds::V2, self::admin())->assertJsonPath('total', 1);
        $this->api('GET', '/api/reservations?userId='.DemoIds::CLIENT1.'&size=1&page=1', self::agent())
            ->assertJsonPath('total', 2)->assertJsonPath('page', 1)->assertJsonPath('size', 1)->assertJsonPath('items.0.id', DemoIds::R_CONFIRMED);
    }

    public function test_filtres_invalides_400(): void
    {
        $this->assertError($this->api('GET', '/api/reservations?status=PAID&size=0&page=x', self::agent()), 400, 'BAD_REQUEST', null, ['status', 'size', 'page']);
    }

    public function test_detail_avec_historique(): void
    {
        $response = $this->api('GET', '/api/reservations/'.DemoIds::R_COMPLETED, self::client2());

        $response->assertOk()
            ->assertJsonPath('status', 'COMPLETED')
            ->assertJsonPath('allowedTransitions', [])
            ->assertJsonPath('createdAt', '2026-09-15T10:12:00Z')
            ->assertJsonCount(4, 'statusHistory')
            ->assertJsonPath('statusHistory.2', [
                'fromStatus' => 'CONFIRMED', 'toStatus' => 'ONGOING', 'changedAt' => '2026-10-01T09:00:00Z',
                'changedBy' => DemoIds::AGENT1, 'comment' => 'Véhicule remis au client',
            ]);
    }

    public function test_detail_d_un_autre_client_403_inconnu_404(): void
    {
        $this->assertError($this->api('GET', '/api/reservations/'.DemoIds::R_COMPLETED, self::client1()), 403, 'FORBIDDEN', 'autre client');
        $this->api('GET', '/api/reservations/'.DemoIds::R_COMPLETED, self::agent())->assertOk();
        $this->assertError($this->api('GET', '/api/reservations/'.self::UNKNOWN, self::agent()), 404, 'NOT_FOUND', 'Réservation introuvable');
        $this->assertError($this->api('GET', '/api/reservations/abc', self::agent()), 404, 'NOT_FOUND');
    }

    // ---------------------------------------------------------------- modification

    public function test_modification_des_dates_en_pending_recalcule_le_prix(): void
    {
        $response = $this->api('PUT', '/api/reservations/'.DemoIds::R_PENDING, self::client1(), [
            'startDate' => '2026-12-10', 'endDate' => '2026-12-15', 'returnAgencyId' => DemoIds::AG_SOUSSE,
        ]);

        $response->assertOk()
            ->assertJsonPath('endDate', '2026-12-15')
            ->assertJsonPath('totalPrice', 450.0) // 5 x 90.000
            ->assertJsonPath('returnAgencyId', DemoIds::AG_SOUSSE)
            ->assertJsonPath('status', 'PENDING');
    }

    public function test_modification_hors_pending_409_et_regles_reappliquees(): void
    {
        $this->assertError(
            $this->api('PUT', '/api/reservations/'.DemoIds::R_CONFIRMED, self::client1(), ['startDate' => '2026-11-21', 'endDate' => '2026-11-26']),
            409, 'CONFLICT', 'Seule une réservation PENDING',
        );
        // Chevauchement avec une autre réservation du même véhicule (la réservation elle-même ne compte pas).
        $this->book(['vehicleId' => DemoIds::V1, 'startDate' => '2026-12-20', 'endDate' => '2026-12-22'])->assertCreated();
        $this->assertError(
            $this->api('PUT', '/api/reservations/'.DemoIds::R_PENDING, self::client1(), ['startDate' => '2026-12-10', 'endDate' => '2026-12-21']),
            409, 'CONFLICT',
        );
        $this->api('PUT', '/api/reservations/'.DemoIds::R_PENDING, self::client1(), ['startDate' => '2026-12-11', 'endDate' => '2026-12-14'])->assertOk();
    }

    public function test_modification_par_un_autre_client_403(): void
    {
        $this->assertError(
            $this->api('PUT', '/api/reservations/'.DemoIds::R_PENDING, self::client2(), ['startDate' => '2026-12-10', 'endDate' => '2026-12-12']),
            403, 'FORBIDDEN',
        );
    }

    // ---------------------------------------------------------------- annulation

    public function test_annulation_pending_par_le_client(): void
    {
        $response = $this->api('POST', '/api/reservations/'.DemoIds::R_PENDING.'/cancel', self::client1(), ['reason' => 'Changement de programme']);

        $response->assertOk()
            ->assertJsonPath('status', 'CANCELLED')
            ->assertJsonPath('allowedTransitions', [])
            ->assertJsonPath('statusHistory.1.fromStatus', 'PENDING')
            ->assertJsonPath('statusHistory.1.toStatus', 'CANCELLED')
            ->assertJsonPath('statusHistory.1.comment', 'Changement de programme')
            ->assertJsonPath('statusHistory.1.changedBy', DemoIds::CLIENT1);
        // Le véhicule est libéré.
        $this->getWithToken('/internal/reservations/active?vehicleId='.DemoIds::V1, TestJwt::internal())->assertJsonPath('active', false);
    }

    public function test_annulation_confirmed_sans_motif(): void
    {
        $this->api('POST', '/api/reservations/'.DemoIds::R_CONFIRMED.'/cancel', self::agent())
            ->assertOk()->assertJsonPath('status', 'CANCELLED')->assertJsonPath('statusHistory.2.comment', null);
    }

    public function test_annulation_impossible_apres_la_remise_du_vehicule_409(): void
    {
        $response = $this->api('POST', '/api/reservations/'.DemoIds::R_COMPLETED.'/cancel', self::client2());
        $this->assertError($response, 409, 'CONFLICT', 'Annulation impossible', ['status']);

        Reservation::query()->whereKey(DemoIds::R_CONFIRMED)->update(['status' => ReservationStatus::ONGOING]);
        $this->assertError($this->api('POST', '/api/reservations/'.DemoIds::R_CONFIRMED.'/cancel', self::client1()), 409, 'CONFLICT');
    }

    public function test_annulation_deux_fois_409(): void
    {
        $this->api('POST', '/api/reservations/'.DemoIds::R_PENDING.'/cancel', self::client1())->assertOk();
        $this->assertError($this->api('POST', '/api/reservations/'.DemoIds::R_PENDING.'/cancel', self::client1()), 409, 'CONFLICT');
    }

    public function test_annulation_d_un_autre_client_403(): void
    {
        $this->assertError($this->api('POST', '/api/reservations/'.DemoIds::R_PENDING.'/cancel', self::client2()), 403, 'FORBIDDEN');
    }

    // ---------------------------------------------------------------- statut (AGENT, ADMIN)

    public function test_cycle_complet_par_un_agent(): void
    {
        $id = $this->book()->json('id');
        foreach (['CONFIRMED', 'ONGOING', 'COMPLETED'] as $status) {
            $this->api('PATCH', "/api/reservations/$id/status", self::agent(), ['status' => $status, 'comment' => "Passage en $status"])
                ->assertOk()->assertJsonPath('status', $status);
        }
        $this->api('GET', "/api/reservations/$id", self::client1())
            ->assertJsonCount(4, 'statusHistory')
            ->assertJsonPath('statusHistory.3.comment', 'Passage en COMPLETED');
    }

    public function test_transition_interdite_409_avec_les_transitions_possibles(): void
    {
        $response = $this->api('PATCH', '/api/reservations/'.DemoIds::R_PENDING.'/status', self::agent(), ['status' => 'COMPLETED']);

        $this->assertError($response, 409, 'CONFLICT', 'PENDING -> COMPLETED', ['status']);
        $response->assertJsonPath('details.0.message', 'Transitions possibles depuis PENDING : CONFIRMED, CANCELLED');

        $this->api('PATCH', '/api/reservations/'.DemoIds::R_COMPLETED.'/status', self::admin(), ['status' => 'ONGOING'])
            ->assertStatus(409)->assertJsonPath('details.0.message', 'Statut COMPLETED final : aucune transition possible');
        $this->assertError($this->api('PATCH', '/api/reservations/'.DemoIds::R_PENDING.'/status', self::agent(), ['status' => 'PENDING']), 409, 'CONFLICT');
    }

    public function test_statut_par_un_client_403_et_corps_invalide_400(): void
    {
        $this->assertError($this->api('PATCH', '/api/reservations/'.DemoIds::R_PENDING.'/status', self::client1(), ['status' => 'CONFIRMED']), 403, 'FORBIDDEN');
        $this->assertError($this->api('PATCH', '/api/reservations/'.DemoIds::R_PENDING.'/status', self::agent(), ['status' => 'PAID']), 400, 'BAD_REQUEST', null, ['status']);
    }

    // ---------------------------------------------------------------- suppression (ADMIN)

    public function test_suppression_d_une_reservation_annulee_204(): void
    {
        $this->api('POST', '/api/reservations/'.DemoIds::R_PENDING.'/cancel', self::client1())->assertOk();

        $this->api('DELETE', '/api/reservations/'.DemoIds::R_PENDING, self::admin())->assertNoContent();
        $this->assertError($this->api('GET', '/api/reservations/'.DemoIds::R_PENDING, self::admin()), 404, 'NOT_FOUND');
        $this->assertDatabaseMissing('reservation_status_history', ['reservation_id' => DemoIds::R_PENDING]);
    }

    public function test_suppression_d_une_reservation_non_annulee_409_ou_sans_role_admin_403(): void
    {
        $this->assertError($this->api('DELETE', '/api/reservations/'.DemoIds::R_COMPLETED, self::admin()), 409, 'CONFLICT', 'CANCELLED');
        $this->assertError($this->api('DELETE', '/api/reservations/'.DemoIds::R_PENDING, self::agent()), 403, 'FORBIDDEN');
        $this->assertError($this->api('DELETE', '/api/reservations/'.DemoIds::R_PENDING, self::client1()), 403, 'FORBIDDEN');
    }

    // ---------------------------------------------------------------- sécurité

    public function test_sans_token_401_et_token_de_service_403(): void
    {
        $this->assertError($this->api('GET', '/api/reservations', null), 401, 'UNAUTHORIZED');
        $this->assertError($this->api('POST', '/api/reservations', null, ['vehicleId' => DemoIds::V4]), 401, 'UNAUTHORIZED');
        // Un compte de service (INTERNAL seul) n'utilise pas l'API publique.
        $this->assertError($this->api('GET', '/api/reservations', TestJwt::internal()), 403, 'FORBIDDEN');
    }
}
