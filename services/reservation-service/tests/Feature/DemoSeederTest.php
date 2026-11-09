<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Support\DemoIds;
use Database\Seeders\DemoReservationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_trois_reservations_de_demo_ids_md(): void
    {
        $this->seed(DemoReservationSeeder::class);
        $this->seed(DemoReservationSeeder::class); // relançable sans doublon

        $this->assertSame(3, Reservation::count());

        $expected = [
            DemoIds::R_PENDING => [DemoIds::CLIENT1, DemoIds::V1, '2026-12-10', '2026-12-13', ReservationStatus::PENDING, '270.000', '500.000'],
            DemoIds::R_CONFIRMED => [DemoIds::CLIENT1, DemoIds::V2, '2026-11-20', '2026-11-25', ReservationStatus::CONFIRMED, '450.000', '500.000'],
            DemoIds::R_COMPLETED => [DemoIds::CLIENT2, DemoIds::V3, '2026-10-01', '2026-10-05', ReservationStatus::COMPLETED, '640.000', '1000.000'],
        ];
        foreach ($expected as $id => [$user, $vehicle, $start, $end, $status, $price, $deposit]) {
            $r = Reservation::findOrFail($id);
            $this->assertSame($user, $r->user_id);
            $this->assertSame($vehicle, $r->vehicle_id);
            $this->assertSame($start, $r->start_date->toDateString());
            $this->assertSame($end, $r->end_date->toDateString());
            $this->assertSame($status, $r->status);
            $this->assertSame($price, $r->total_price);
            $this->assertSame($deposit, $r->deposit_amount);
            $this->assertSame('TND', $r->currency);
        }
    }
}
