<?php

namespace Database\Seeders;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Support\DemoIds;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Réservations exactes de docs/demo-ids.md (mêmes UUID, mêmes montants que les paiements de démo).
 * Relancer le seeder remet ces trois réservations dans leur état initial sans toucher aux autres.
 * totalPrice = jours (endDate - startDate) x dailyRate de la catégorie ; depositAmount = caution de la catégorie.
 */
class DemoReservationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::rows() as $id => $row) {
            $reservation = Reservation::query()->findOrNew($id);
            $reservation->id = $id;
            $reservation->fill($row['attributes']);
            $reservation->created_at = Carbon::parse($row['createdAt']);
            $reservation->updated_at = Carbon::parse($row['updatedAt']);
            $reservation->timestamps = false;
            $reservation->save();
        }
    }

    /** @return array<string, array{attributes: array<string, mixed>, createdAt: string, updatedAt: string}> */
    public static function rows(): array
    {
        return [
            // r-pending : client1, v1 (économique, Tunis), 3 jours x 90.000
            DemoIds::R_PENDING => [
                'attributes' => [
                    'user_id' => DemoIds::CLIENT1,
                    'vehicle_id' => DemoIds::V1,
                    'category_id' => DemoIds::CAT_ECONOMIQUE,
                    'pickup_agency_id' => DemoIds::AG_TUNIS,
                    'return_agency_id' => DemoIds::AG_TUNIS,
                    'start_date' => '2026-12-10',
                    'end_date' => '2026-12-13',
                    'status' => ReservationStatus::PENDING,
                    'total_price' => '270.000',
                    'deposit_amount' => '500.000',
                    'currency' => 'TND',
                ],
                'createdAt' => '2026-11-09T08:00:00Z',
                'updatedAt' => '2026-11-09T08:00:00Z',
            ],
            // r-confirmed : client1, v2 (économique, Tunis), 5 jours x 90.000, payée par p-confirmed
            DemoIds::R_CONFIRMED => [
                'attributes' => [
                    'user_id' => DemoIds::CLIENT1,
                    'vehicle_id' => DemoIds::V2,
                    'category_id' => DemoIds::CAT_ECONOMIQUE,
                    'pickup_agency_id' => DemoIds::AG_TUNIS,
                    'return_agency_id' => DemoIds::AG_TUNIS,
                    'start_date' => '2026-11-20',
                    'end_date' => '2026-11-25',
                    'status' => ReservationStatus::CONFIRMED,
                    'total_price' => '450.000',
                    'deposit_amount' => '500.000',
                    'currency' => 'TND',
                ],
                'createdAt' => '2026-11-08T14:00:00Z',
                'updatedAt' => '2026-11-08T14:30:00Z',
            ],
            // r-completed : client2, v3 (SUV, Sousse), 4 jours x 160.000 ; bookedAt de docs/events/ReservationCompleted.json
            DemoIds::R_COMPLETED => [
                'attributes' => [
                    'user_id' => DemoIds::CLIENT2,
                    'vehicle_id' => DemoIds::V3,
                    'category_id' => DemoIds::CAT_SUV,
                    'pickup_agency_id' => DemoIds::AG_SOUSSE,
                    'return_agency_id' => DemoIds::AG_SOUSSE,
                    'start_date' => '2026-10-01',
                    'end_date' => '2026-10-05',
                    'status' => ReservationStatus::COMPLETED,
                    'total_price' => '640.000',
                    'deposit_amount' => '1000.000',
                    'currency' => 'TND',
                ],
                'createdAt' => '2026-09-15T10:12:00Z',
                'updatedAt' => '2026-10-05T18:45:00Z',
            ],
        ];
    }
}
