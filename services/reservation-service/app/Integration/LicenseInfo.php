<?php

namespace App\Integration;

use Carbon\CarbonImmutable;

/** Permis de conduire, tel que renvoyé dans GET /internal/users/{id} (champ license). */
final class LicenseInfo
{
    public function __construct(
        public readonly string $number,
        public readonly string $category,
        public readonly CarbonImmutable $issueDate,
        public readonly CarbonImmutable $expiryDate,
        public readonly bool $verified,
    ) {
    }

    public static function fromPayload(array $data): self
    {
        return new self(
            Payload::string($data, 'number'),
            Payload::string($data, 'category'),
            Payload::date($data, 'issueDate'),
            Payload::date($data, 'expiryDate'),
            Payload::bool($data, 'verified'),
        );
    }

    /** Valide jusqu'au jour de retour inclus. */
    public function isValidUntil(CarbonImmutable $date): bool
    {
        return $this->expiryDate->greaterThanOrEqualTo($date);
    }
}
