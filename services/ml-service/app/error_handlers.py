"""Erreurs de l'API au format commun de l'équipe :
{"status": 409, "error": "CONFLICT", "message": "...", "details": [{"field": "...", "message": "..."}]}

1) Le code métier LÈVE une exception (ex. raise NotFoundError("...")) sans s'occuper du HTTP.
2) Les « handlers » enregistrés ici l'ATTRAPENT et la transforment en réponse JSON au bon format.
"""
import logging
from http import HTTPStatus

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException as StarletteHTTPException

logger = logging.getLogger("ml-service")


# ---------- Exceptions métier ----------

class ApiError(Exception):
    """Classe mère : chaque sous-classe fixe son code HTTP (et, si besoin, des en-têtes)."""
    status_code = 500
    headers: dict[str, str] = {}

    def __init__(self, message: str, details: list[dict] | None = None):
        super().__init__(message)
        self.message = message
        self.details = details or []


class BadRequestError(ApiError):
    status_code = 400


class UnauthorizedError(ApiError):
    """401 : token absent, expiré ou invalide. L'en-tête standard indique d'envoyer un token Bearer."""
    status_code = 401
    headers = {"WWW-Authenticate": "Bearer"}


class ForbiddenError(ApiError):
    """403 : token valide, mais sans le rôle requis."""
    status_code = 403


class NotFoundError(ApiError):
    status_code = 404


class ConflictError(ApiError):
    status_code = 409


class BusinessValidationError(ApiError):
    status_code = 422


class ServiceUnavailableError(ApiError):
    """503 : un service dont on dépend est indisponible (ex. Keycloak pour vérifier les tokens)."""
    status_code = 503


class NoActiveModelError(ServiceUnavailableError):
    """503 : aucune version du modèle n'est active."""


# ---------- Construction de la réponse ----------

def error_response(status_code: int, message: str, details: list[dict] | None = None,
                   headers: dict[str, str] | None = None) -> JSONResponse:
    """Réponse JSON au format commun. error = nom standard du code (404 -> NOT_FOUND)."""
    return JSONResponse(status_code=status_code, headers=headers, content={
        "status": status_code,
        "error": HTTPStatus(status_code).name,
        "message": message,
        "details": details or [],
    })


# Messages en français pour les erreurs de validation les plus courantes de Pydantic
VALIDATION_MESSAGES = {
    "missing": "champ obligatoire",
    "uuid_parsing": "UUID invalide",
    "uuid_type": "UUID invalide",
    "date_from_datetime_parsing": "date invalide (format attendu : AAAA-MM-JJ)",
    "date_parsing": "date invalide (format attendu : AAAA-MM-JJ)",
    "date_type": "date invalide (format attendu : AAAA-MM-JJ)",
    "float_parsing": "nombre attendu",
    "float_type": "nombre attendu",
    "int_parsing": "nombre entier attendu",
    "string_type": "texte attendu",
    "string_too_short": "ne doit pas être vide",
    "string_too_long": "trop long",
    "extra_forbidden": "champ non autorisé",
    "greater_than_equal": "valeur trop petite",
    "less_than_equal": "valeur trop grande",
    "model_attributes_type": "objet JSON attendu",
}


def describe_validation_error(error: dict) -> dict:
    """Transforme une erreur Pydantic en {"field", "message"}.
    loc vaut par ex. ("body", "startDate") ou ("query", "page") : on garde la fin."""
    location = [str(part) for part in error["loc"][1:]] or [str(error["loc"][0])]
    return {
        "field": ".".join(location),
        "message": VALIDATION_MESSAGES.get(error["type"], error["msg"]),
    }


# ---------- Handlers ----------

async def handle_api_error(request: Request, error: ApiError) -> JSONResponse:
    """Nos exceptions métier (401, 403, 404, 409, 422, 503...)."""
    return error_response(error.status_code, error.message, error.details, error.headers or None)


async def handle_request_validation_error(request: Request, error: RequestValidationError) -> JSONResponse:
    """Erreurs détectées par FastAPI/Pydantic AVANT d'entrer dans nos fonctions.
    - JSON illisible, paramètre d'URL invalide (page=-1, id non UUID) -> 400
    - contenu du corps incorrect (champ manquant, mauvais type)        -> 422"""
    errors = error.errors()
    if any(item["type"] == "json_invalid" for item in errors):
        return error_response(400, "Corps de requête illisible : JSON invalide")

    details = [describe_validation_error(item) for item in errors]
    if all(item["loc"][0] == "body" for item in errors):
        return error_response(422, "Données de la requête invalides", details)
    return error_response(400, "Paramètres de la requête invalides", details)


async def handle_http_exception(request: Request, error: StarletteHTTPException) -> JSONResponse:
    """Erreurs HTTP produites par le framework (corps illisible -> 400, route inconnue -> 404,
    mauvaise méthode -> 405)."""
    messages = {
        400: "Corps de requête illisible (JSON en UTF-8 attendu)",
        404: "Route introuvable",
        405: "Méthode non autorisée sur cette route",
    }
    return error_response(error.status_code, messages.get(error.status_code, str(error.detail)))


async def handle_unexpected_error(request: Request, error: Exception) -> JSONResponse:
    """Filet de sécurité : bug imprévu -> 500 au bon format, détail seulement dans les logs."""
    logger.exception("Erreur inattendue sur %s %s", request.method, request.url.path)
    return error_response(500, "Erreur interne du service")


def register_error_handlers(app: FastAPI) -> None:
    """Branche tous les handlers sur l'application (appelé dans main.py)."""
    app.add_exception_handler(ApiError, handle_api_error)
    app.add_exception_handler(RequestValidationError, handle_request_validation_error)
    app.add_exception_handler(StarletteHTTPException, handle_http_exception)
    app.add_exception_handler(Exception, handle_unexpected_error)
