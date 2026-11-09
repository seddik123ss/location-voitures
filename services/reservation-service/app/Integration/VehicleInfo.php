<?php

namespace App\Integration;

/**
 * GET /internal/vehicles/{id} de vehicle-service (docs/internal-api.md) :
 * {id, plate, brand, model, status, mileage, agencyId, category: {id, name, dailyRate, deposit}}.
 */
final class VehicleInfo
{
    /** Statuts qui empêchent toute réservation (409). */
    public const UNAVAILABLE_STATUSES = ['MAINTENANCE', 'OUT_OF_SERVICE'];

    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $agencyId,
        public readonly string $categoryId,
        public readonly string $categoryName,
        public readonly string $dailyRate,
        public readonly string $deposit,
    ) {
    }

    /** @throws InvalidPayload */
    public static function fromPayload(array $data): self
    {
        $category = Payload::object($data, 'category');

        return new self(
            Payload::uuid($data, 'id'),
            Payload::string($data, 'status'),
            Payload::uuid($data, 'agencyId'),
            Payload::uuid($category, 'id'),
            Payload::string($category, 'name'),
            Payload::amount($category, 'dailyRate'),
            Payload::amount($category, 'deposit'),
        );
    }

    public function isBookable(): bool
    {
        return ! in_array($this->status, self::UNAVAILABLE_STATUSES, true);
    }
}
