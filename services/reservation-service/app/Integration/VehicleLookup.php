<?php

namespace App\Integration;

use App\Exceptions\ApiException;

/** Accès à vehicle-service (RENTAL-VEHICLE). */
interface VehicleLookup
{
    /**
     * @return VehicleInfo|null null si le véhicule n'existe pas
     *
     * @throws ApiException 503 (service indisponible) ou 502 (réponse illisible)
     */
    public function find(string $vehicleId): ?VehicleInfo;
}
