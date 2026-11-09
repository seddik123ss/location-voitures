<?php

namespace App\Integration\Http;

use App\Integration\ReservationInfo;
use App\Integration\ReservationLookup;

/** GET /internal/reservations/{id} sur RENTAL-RESERVATION (docs/internal-api.md). */
final class HttpReservationLookup implements ReservationLookup
{
    public const APP = 'RENTAL-RESERVATION';

    public function __construct(private readonly InternalApiClient $client)
    {
    }

    public function find(string $reservationId): ?ReservationInfo
    {
        $data = $this->client->getJson(self::APP, '/internal/reservations/' . rawurlencode(strtolower($reservationId)));
        if ($data === null) {
            return null;
        }

        return new ReservationInfo(
            Payload::uuid($data, 'id', self::APP),
            Payload::uuid($data, 'userId', self::APP),
            Payload::string($data, 'status', self::APP),
        );
    }
}
