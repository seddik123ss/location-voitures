<?php

namespace App\Integration;

/**
 * GET /internal/users/{id} de user-service (docs/internal-api.md) :
 * {id, firstname, lastname, email, phone, role, active, license: {number, category, issueDate, expiryDate, verified} ou null}.
 * Seuls les champs utiles aux règles de réservation sont lus.
 */
final class UserInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $role,
        public readonly bool $active,
        public readonly ?LicenseInfo $license,
    ) {
    }

    /** @throws InvalidPayload */
    public static function fromPayload(array $data): self
    {
        if (! array_key_exists('license', $data)) {
            throw new InvalidPayload('Champ license absent (null attendu si pas de permis)');
        }

        return new self(
            Payload::uuid($data, 'id'),
            Payload::string($data, 'role'),
            Payload::bool($data, 'active'),
            $data['license'] === null ? null : LicenseInfo::fromPayload(Payload::object($data, 'license')),
        );
    }
}
