"""Vérification des tokens JWT de Keycloak (PyJWT) et contrôle des rôles.

Keycloak SIGNE chaque token avec sa clé privée (RS256) et publie ses clés PUBLIQUES (JWKS).
Pour chaque requête, on vérifie : la signature, l'expiration (exp), l'émetteur (iss),
puis on lit les rôles dans la clé "roles" du token (mapper roles-flat du realm).
La clé publique est gardée en cache : Keycloak n'est pas appelé à chaque requête.

Utilisation : APIRouter(dependencies=[Depends(require_roles("ADMIN"))])
"""
from collections.abc import Callable

import jwt
from fastapi import Depends
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

from app.error_handlers import ForbiddenError, ServiceUnavailableError, UnauthorizedError
from app.settings import settings

# Lit l'en-tête « Authorization: Bearer <token> ». auto_error=False : on renvoie nous-mêmes
# une 401 au format de l'équipe. scheme_name = nom du schéma dans Swagger et dans le YAML.
bearer_scheme = HTTPBearer(auto_error=False, scheme_name="bearerAuth",
                           description="Token d'accès Keycloak (realm location-voitures)")

_jwks_client: jwt.PyJWKClient | None = None


def get_signing_key(token: str):
    """Clé publique de Keycloak qui a signé ce token (trouvée par son « kid » dans le JWKS, en cache).
    Fonction séparée pour pouvoir la remplacer dans les tests par une clé de test."""
    global _jwks_client
    if _jwks_client is None:
        _jwks_client = jwt.PyJWKClient(settings.jwks_url, cache_keys=True, lifespan=3600)
    return _jwks_client.get_signing_key_from_jwt(token).key


def decode_token(token: str) -> dict:
    """Vérifie le token et renvoie son contenu (claims). 401 s'il est invalide, 503 si Keycloak est injoignable."""
    try:
        return jwt.decode(
            token,
            get_signing_key(token),
            algorithms=["RS256"],
            issuer=settings.keycloak_issuer,
            # Audience non vérifiée, comme la Gateway : aud vaut "account" pour les utilisateurs
            # et n'existe pas toujours pour les comptes de service.
            options={"verify_aud": False, "require": ["exp", "iss"]},
        )
    except jwt.ExpiredSignatureError:
        raise UnauthorizedError("Token expiré : reconnectez-vous") from None
    except jwt.PyJWKClientConnectionError:
        raise ServiceUnavailableError("Keycloak injoignable : impossible de vérifier le token") from None
    except (jwt.PyJWKClientError, jwt.InvalidTokenError):
        raise UnauthorizedError("Token invalide") from None


def require_roles(*allowed_roles: str) -> Callable:
    """Dépendance FastAPI : laisse passer si le token est valide ET contient au moins un des rôles."""
    def check_token(credentials: HTTPAuthorizationCredentials | None = Depends(bearer_scheme)) -> dict:
        if credentials is None:
            raise UnauthorizedError("Token absent : en-tête Authorization: Bearer <token> attendu")
        claims = decode_token(credentials.credentials)
        token_roles = set(claims.get("roles") or [])
        if not token_roles & set(allowed_roles):
            raise ForbiddenError(f"Rôle insuffisant : {' ou '.join(allowed_roles)} requis")
        return claims
    return check_token
