"""
Caller identity and role checks.

The views only rely on request.user being a Principal (user_id + roles) and on the
permission classes below. Where the Principal comes from is configured in settings:

- AUTH_MODE=dev (step 2, before Keycloak): DevHeaderAuthentication reads
      X-User-Id: <uuid>          X-User-Roles: CLIENT,ADMIN
  NEVER enable this outside local development: anyone can claim any identity.
- AUTH_MODE=jwt (step 3): Keycloak JWT authentication (PyJWT + PyJWKClient),
  roles taken from the "roles" claim, user id from "sub".
"""
import uuid
from dataclasses import dataclass, field

from rest_framework.authentication import BaseAuthentication
from rest_framework.exceptions import AuthenticationFailed, NotAuthenticated
from rest_framework.permissions import BasePermission


class Role:
    CLIENT = "CLIENT"
    AGENT = "AGENT"
    ADMIN = "ADMIN"
    INTERNAL = "INTERNAL"
    ALL = {CLIENT, AGENT, ADMIN, INTERNAL}


@dataclass(frozen=True)
class Principal:
    user_id: uuid.UUID | None
    roles: frozenset = field(default_factory=frozenset)

    is_authenticated = True  # DRF / Django compatibility

    def has(self, *roles: str) -> bool:
        return bool(self.roles.intersection(roles))

    @property
    def is_staff_like(self) -> bool:
        """AGENT and ADMIN can see every payment."""
        return self.has(Role.AGENT, Role.ADMIN)


class DevHeaderAuthentication(BaseAuthentication):
    def authenticate(self, request):
        raw_id = request.headers.get("X-User-Id")
        raw_roles = request.headers.get("X-User-Roles")
        if not raw_id and not raw_roles:
            return None  # anonymous -> 401 from the permission check
        roles = frozenset(r.strip().upper() for r in (raw_roles or "").split(",") if r.strip())
        unknown = roles - Role.ALL
        if unknown:
            raise AuthenticationFailed(f"Rôle(s) inconnu(s) : {', '.join(sorted(unknown))}")
        try:
            user_id = uuid.UUID(raw_id) if raw_id else None
        except ValueError:
            raise AuthenticationFailed("X-User-Id doit être un UUID")
        if user_id is None and Role.INTERNAL not in roles:
            raise AuthenticationFailed("X-User-Id manquant")
        return Principal(user_id=user_id, roles=roles), None

    def authenticate_header(self, request):
        # Makes DRF answer 401 (not 403) when credentials are missing.
        return 'Bearer realm="location-voitures"'


def has_role(*roles: str) -> type[BasePermission]:
    """Permission class factory: has_role("AGENT", "ADMIN")."""

    class HasRole(BasePermission):
        message = f"Rôle requis : {' ou '.join(roles)}"

        def has_permission(self, request, view):
            user = request.user
            if not isinstance(user, Principal):
                raise NotAuthenticated("Authentification requise")
            return user.has(*roles)

    HasRole.__name__ = f"HasRole_{'_'.join(roles)}"
    return HasRole
