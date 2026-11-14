"""
Keycloak (realm location-voitures).

1. KeycloakJWTAuthentication (AUTH_MODE=jwt): validates the incoming "Authorization: Bearer"
   token with PyJWT + PyJWKClient:
     - signature checked with the realm public keys (JWKS, cached, refreshed on unknown kid);
     - algorithm RS256 only; issuer = KEYCLOAK_ISSUER; exp/iat/iss/sub required (30 s leeway);
     - audience not checked: the realm has no audience mapper (aud = "account");
     - user = "sub" (UUID, same ids as docs/demo-ids.md), roles = "roles" claim
       filtered to CLIENT/AGENT/ADMIN/INTERNAL (Keycloak adds offline_access, ...).
   Invalid/expired token -> 401; Keycloak unreachable while fetching keys -> 503.

2. ServiceTokenProvider: service-account token (client_credentials, client payment-service,
   role INTERNAL) for calls to other services' /internal routes; cached until shortly
   before expiry.
"""
from __future__ import annotations

import logging
import threading
import time
import uuid

import jwt
import requests
from django.conf import settings
from rest_framework.authentication import BaseAuthentication
from rest_framework.exceptions import AuthenticationFailed

from .auth import Principal, Role
from .exceptions import ServiceUnavailableError

logger = logging.getLogger(__name__)

_jwk_client: jwt.PyJWKClient | None = None
_jwk_lock = threading.Lock()


def jwk_client() -> jwt.PyJWKClient:
    global _jwk_client
    with _jwk_lock:
        if _jwk_client is None:
            _jwk_client = jwt.PyJWKClient(settings.KEYCLOAK_JWKS_URL, cache_keys=True, lifespan=600, timeout=5)
        return _jwk_client


def decode_token(token: str) -> dict:
    try:
        signing_key = jwk_client().get_signing_key_from_jwt(token)
    except jwt.PyJWKClientConnectionError as exc:
        logger.error("Keycloak JWKS unreachable (%s): %s", settings.KEYCLOAK_JWKS_URL, exc)
        raise ServiceUnavailableError("Keycloak est injoignable : impossible de vérifier le token")
    except (jwt.PyJWKClientError, jwt.DecodeError):
        raise AuthenticationFailed("Token invalide (clé de signature inconnue ou format incorrect)")
    try:
        return jwt.decode(
            token, signing_key.key, algorithms=["RS256"], issuer=settings.KEYCLOAK_ISSUER, leeway=30,
            options={"require": ["exp", "iat", "iss", "sub"], "verify_aud": False},
        )
    except jwt.ExpiredSignatureError:
        raise AuthenticationFailed("Token expiré")
    except jwt.InvalidIssuerError:
        raise AuthenticationFailed("Token émis par un autre serveur que le realm location-voitures")
    except jwt.InvalidTokenError as exc:
        raise AuthenticationFailed(f"Token invalide ({exc})")


class KeycloakJWTAuthentication(BaseAuthentication):
    def authenticate(self, request):
        header = request.headers.get("Authorization")
        if not header:
            return None  # anonymous -> 401 from the permission check
        scheme, _, token = header.partition(" ")
        if scheme.lower() != "bearer" or not token.strip():
            raise AuthenticationFailed("En-tête attendu : Authorization: Bearer <token>")
        claims = decode_token(token.strip())
        try:
            user_id = uuid.UUID(str(claims["sub"]))
        except ValueError:
            raise AuthenticationFailed("Claim sub invalide (UUID attendu)")
        roles = frozenset(r for r in claims.get("roles") or [] if r in Role.ALL)
        return Principal(user_id=user_id, roles=roles), claims

    def authenticate_header(self, request):
        return 'Bearer realm="location-voitures"'


class ServiceTokenProvider:
    """Callable returning a valid service-account access token (thread-safe, cached)."""

    def __init__(self, session: requests.Session | None = None, now=time.time):
        self._session = session or requests.Session()
        self._now = now
        self._lock = threading.Lock()
        self._token: str | None = None
        self._expires_at = 0.0

    @property
    def token_url(self) -> str:
        return settings.KEYCLOAK_TOKEN_URL  # default: <KEYCLOAK_ISSUER>/protocol/openid-connect/token

    def __call__(self) -> str:
        with self._lock:
            if self._token and self._now() < self._expires_at - 30:
                return self._token
            try:
                response = self._session.post(self.token_url, timeout=5, data={
                    "grant_type": "client_credentials",
                    "client_id": settings.KEYCLOAK_CLIENT_ID,
                    "client_secret": settings.KEYCLOAK_CLIENT_SECRET,
                })
            except requests.RequestException as exc:
                logger.error("Keycloak token endpoint unreachable: %s", exc)
                raise ServiceUnavailableError("Keycloak est injoignable : token de service impossible à obtenir")
            if response.status_code != 200:
                logger.error("Service token refused by Keycloak: HTTP %s %s", response.status_code, response.text[:200])
                raise ServiceUnavailableError(
                    "Keycloak a refusé le token de service (vérifier KEYCLOAK_CLIENT_ID / KEYCLOAK_CLIENT_SECRET)"
                )
            data = response.json()
            self._token = data["access_token"]
            self._expires_at = self._now() + float(data.get("expires_in", 60))
            return self._token


service_token = ServiceTokenProvider()
