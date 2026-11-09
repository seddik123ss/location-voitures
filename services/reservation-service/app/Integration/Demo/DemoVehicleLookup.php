<?php

namespace App\Integration\Demo;

use App\Integration\VehicleInfo;
use App\Integration\VehicleLookup;
use App\Support\DemoIds;
use Illuminate\Support\Facades\Log;

/**
 * Simulation de vehicle-service (LOOKUP_MODE=demo) : véhicules de docs/demo-ids.md (v6 en MAINTENANCE),
 * au format exact de GET /internal/vehicles/{id}.
 */
final class DemoVehicleLookup implements VehicleLookup
{
    public function find(string $vehicleId): ?VehicleInfo
    {
        Log::info("[SIMULATION] véhicule $vehicleId (LOOKUP_MODE=demo)");
        $payload = self::payloads()[strtolower($vehicleId)] ?? null;

        return $payload === null ? null : VehicleInfo::fromPayload($payload);
    }

    /** @return array<string, array<string, mixed>> */
    public static function payloads(): array
    {
        $eco = ['id' => DemoIds::CAT_ECONOMIQUE, 'name' => 'Économique', 'dailyRate' => 90.0, 'deposit' => 500.0];
        $suv = ['id' => DemoIds::CAT_SUV, 'name' => 'SUV', 'dailyRate' => 160.0, 'deposit' => 1000.0];
        $luxe = ['id' => DemoIds::CAT_LUXE, 'name' => 'Luxe', 'dailyRate' => 300.0, 'deposit' => 2000.0];
        $v = static fn (string $id, string $plate, string $brand, string $model, string $status, int $mileage, string $agency, array $cat): array => [
            'id' => $id, 'plate' => $plate, 'brand' => $brand, 'model' => $model, 'status' => $status,
            'mileage' => $mileage, 'agencyId' => $agency, 'category' => $cat,
        ];

        return [
            DemoIds::V1 => $v(DemoIds::V1, '123 TU 4501', 'Peugeot', '208', 'AVAILABLE', 15000, DemoIds::AG_TUNIS, $eco),
            DemoIds::V2 => $v(DemoIds::V2, '124 TU 4502', 'Renault', 'Clio', 'AVAILABLE', 38000, DemoIds::AG_TUNIS, $eco),
            DemoIds::V3 => $v(DemoIds::V3, '125 TU 4503', 'Kia', 'Sportage', 'AVAILABLE', 42980, DemoIds::AG_SOUSSE, $suv),
            DemoIds::V4 => $v(DemoIds::V4, '126 TU 4504', 'Hyundai', 'Tucson', 'AVAILABLE', 27000, DemoIds::AG_TUNIS, $suv),
            DemoIds::V5 => $v(DemoIds::V5, '127 TU 4505', 'Mercedes', 'Classe C', 'AVAILABLE', 12000, DemoIds::AG_TUNIS, $luxe),
            DemoIds::V6 => $v(DemoIds::V6, '128 TU 4506', 'Volkswagen', 'Polo', 'MAINTENANCE', 61000, DemoIds::AG_SOUSSE, $eco),
        ];
    }
}
