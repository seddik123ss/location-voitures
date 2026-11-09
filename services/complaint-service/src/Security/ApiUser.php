<?php

namespace App\Security;

use App\Enum\AuthorRole;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Appelant authentifié : id (= sub Keycloak, UUID) et rôles du realm (CLIENT, AGENT, ADMIN, INTERNAL).
 * Produit par l'authenticator de dev, puis par l'authenticator JWT.
 */
final class ApiUser implements UserInterface
{
    public const REALM_ROLES = ['CLIENT', 'AGENT', 'ADMIN', 'INTERNAL'];

    /** @param list<string> $realmRoles */
    public function __construct(private readonly string $id, private readonly array $realmRoles)
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->id;
    }

    /** @return list<string> ROLE_CLIENT, ROLE_AGENT... */
    public function getRoles(): array
    {
        return array_map(static fn (string $r) => 'ROLE_' . $r, $this->realmRoles);
    }

    /** @return list<string> */
    public function getRealmRoles(): array
    {
        return $this->realmRoles;
    }

    public function hasRole(string $realmRole): bool
    {
        return in_array($realmRole, $this->realmRoles, true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('ADMIN');
    }

    /** AGENT ou ADMIN : traitent les réclamations. */
    public function isStaff(): bool
    {
        return $this->hasRole('AGENT') || $this->hasRole('ADMIN');
    }

    /** Rôle retenu pour tracer un message ou un changement de statut. */
    public function authorRole(): AuthorRole
    {
        return match (true) {
            $this->hasRole('ADMIN') => AuthorRole::ADMIN,
            $this->hasRole('AGENT') => AuthorRole::AGENT,
            default => AuthorRole::CLIENT,
        };
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
