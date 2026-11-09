<?php

namespace App\Integration\Http;

use App\Exceptions\ApiException;
use App\Integration\InvalidPayload;
use App\Integration\VehicleInfo;
use App\Integration\VehicleLookup;

/** GET /internal/vehicles/{id} sur RENTAL-VEHICLE (LOOKUP_MODE=eureka). */
final class HttpVehicleLookup implements VehicleLookup
{
    public const APP = 'RENTAL-VEHICLE';

    public function __construct(private readonly InternalApiClient $client)
    {
    }

    public function find(string $vehicleId): ?VehicleInfo
    {
        $data = $this->client->getJson(self::APP, '/internal/vehicles/'.rawurlencode(strtolower($vehicleId)));
        if ($data === null) {
            return null;
        }
        try {
            return VehicleInfo::fromPayload($data);
        } catch (InvalidPayload $e) {
            throw ApiException::badGateway('Réponse illisible du service '.self::APP.' : '.$e->getMessage(), $e);
        }
    }
}
