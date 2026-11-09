"""OpenAPI helpers (drf-spectacular): auth schemes and reusable error responses."""
from drf_spectacular.extensions import OpenApiAuthenticationExtension
from drf_spectacular.utils import OpenApiExample, OpenApiParameter, OpenApiResponse

from .serializers import ErrorSerializer


class DevHeaderAuthenticationScheme(OpenApiAuthenticationExtension):
    """Shows the two dev headers in Swagger UI's "Authorize" dialog (AUTH_MODE=dev)."""
    target_class = "payments.auth.DevHeaderAuthentication"
    name = ["devUserId", "devUserRoles"]

    def get_security_definition(self, auto_schema):
        return [
            {"type": "apiKey", "in": "header", "name": "X-User-Id",
             "description": "DEV uniquement : UUID de l'utilisateur (remplacé par le JWT Keycloak à l'étape 3)."},
            {"type": "apiKey", "in": "header", "name": "X-User-Roles",
             "description": "DEV uniquement : rôles séparés par des virgules, ex. CLIENT ou AGENT,ADMIN."},
        ]


def publish_with_bearer_auth(result, generator, request, public):
    """
    Postprocessing hook. When OPENAPI_FOR_DOCS=1 (generation of docs/openapi/payment-service.yaml),
    replace the local dev header schemes by the team's bearerAuth (Keycloak JWT), so the shared
    contract shows the real authentication. Swagger UI in dev keeps the dev headers.
    """
    import os
    if os.getenv("OPENAPI_FOR_DOCS") != "1":
        return result
    result["servers"] = [
        {"url": "http://localhost:8088", "description": "via la Gateway (routes /api)"},
        {"url": "http://localhost:8104", "description": "accès direct au service (développement, routes /internal)"},
    ]
    components = result.setdefault("components", {})
    components["securitySchemes"] = {"bearerAuth": {"type": "http", "scheme": "bearer", "bearerFormat": "JWT"}}
    for path_item in result.get("paths", {}).values():
        for operation in path_item.values():
            if isinstance(operation, dict) and operation.get("security"):
                operation["security"] = [{"bearerAuth": []}]
    result["security"] = [{"bearerAuth": []}]
    return result


class KeycloakJWTAuthenticationScheme(OpenApiAuthenticationExtension):
    target_class = "payments.keycloak.KeycloakJWTAuthentication"
    name = "bearerAuth"

    def get_security_definition(self, auto_schema):
        return {"type": "http", "scheme": "bearer", "bearerFormat": "JWT",
                "description": "Token Keycloak du realm location-voitures (rôles dans la claim `roles`)."}


IDEMPOTENCY_KEY_PARAM = OpenApiParameter(
    name="Idempotency-Key", type=str, location=OpenApiParameter.HEADER, required=True,
    description="Clé unique générée par le client (UUID conseillé). Obligatoire (400 sinon). "
                "Une clé déjà utilisée renvoie 409, même pour une requête identique.",
)
CORRELATION_PARAM = OpenApiParameter(
    name="X-Correlation-Id", type=str, location=OpenApiParameter.HEADER, required=False,
    description="Identifiant de corrélation, renvoyé tel quel (généré s'il est absent).",
)


def error(status: int, description: str, message: str, details=None, code=None) -> OpenApiResponse:
    from .exceptions import ERROR_CODES
    return OpenApiResponse(
        response=ErrorSerializer,
        description=description,
        examples=[OpenApiExample(
            name=f"{status}", response_only=True, status_codes=[str(status)],
            value={"status": status, "error": code or ERROR_CODES[status], "message": message,
                   "details": details or []},
        )],
    )


E400 = error(400, "Requête invalide (corps, paramètres ou en-tête)", "La requête contient des champs invalides",
             [{"field": "amount", "message": "Assurez-vous qu'il n'y a pas plus de 3 décimales."}])
E401 = error(401, "Non authentifié", "Authentification requise")
E403 = error(403, "Rôle insuffisant ou ressource d'un autre utilisateur", "Rôle requis : ADMIN")
E404 = error(404, "Ressource introuvable", "Paiement 6f1c2a7e-3b9d-4c1e-9a55-2f7d8e0b1c3a introuvable")
E502 = error(502, "Réponse illisible du service des réservations", "Réponse illisible du service des réservations")
E503 = error(503, "Service des réservations ou RabbitMQ indisponible", "Le service des réservations est indisponible")
