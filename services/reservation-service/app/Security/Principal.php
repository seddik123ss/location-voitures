<?php

namespace App\Security;

/** Appelant authentifié par son token Keycloak : utilisateur ou compte de service. */
final class Principal
{
    public const REALM_ROLES = ['CLIENT', 'AGENT', 'ADMIN', 'INTERNAL'];

    /** @param list<string> $roles rôles du realm présents dans le claim "roles" */
    public function __construct(
        public readonly string $id,
        public readonly array $roles,
        public readonly ?string $clientId = null,
    ) {
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasAnyRole(string ...$roles): bool
    {
        return array_intersect($roles, $this->roles) !== [];
    }
}
