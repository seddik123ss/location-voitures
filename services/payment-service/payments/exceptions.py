"""
Common error format (docs/conventions.md):

    {"status": 409, "error": "CONFLICT", "message": "...", "details": [{"field": "...", "message": "..."}]}

- Business errors are raised as ApiError subclasses from the service layer.
- api_exception_handler (REST_FRAMEWORK["EXCEPTION_HANDLER"]) converts ApiError and every
  DRF/Django exception (validation 400, 401, 403, 404, 405, ...) to this format.
- json_404 / json_500 cover URLs and crashes outside DRF views.
"""
import logging
import re

from django.http import JsonResponse
from rest_framework import exceptions as drf_exceptions
from rest_framework import status as http
from rest_framework.response import Response
from rest_framework.settings import api_settings
from rest_framework.views import exception_handler as drf_exception_handler
from rest_framework.views import set_rollback

logger = logging.getLogger(__name__)

ERROR_CODES = {
    400: "BAD_REQUEST",
    401: "UNAUTHORIZED",
    403: "FORBIDDEN",
    404: "NOT_FOUND",
    405: "METHOD_NOT_ALLOWED",
    406: "NOT_ACCEPTABLE",
    409: "CONFLICT",
    415: "UNSUPPORTED_MEDIA_TYPE",
    422: "UNPROCESSABLE_ENTITY",
    429: "TOO_MANY_REQUESTS",
    500: "INTERNAL_SERVER_ERROR",
    502: "BAD_GATEWAY",
    503: "SERVICE_UNAVAILABLE",
}


def error_body(status_code: int, message: str, details=None) -> dict:
    return {
        "status": status_code,
        "error": ERROR_CODES.get(status_code, "ERROR"),
        "message": message,
        "details": details or [],
    }


# --- Business exceptions -----------------------------------------------------

class ApiError(Exception):
    """Base class: raise a subclass anywhere in the service layer to answer with this status."""
    status_code = http.HTTP_500_INTERNAL_SERVER_ERROR
    default_message = "Erreur interne"

    def __init__(self, message: str | None = None, details: list[dict] | None = None):
        self.message = message or self.default_message
        self.details = details or []
        super().__init__(self.message)


class BadRequestError(ApiError):
    status_code = http.HTTP_400_BAD_REQUEST
    default_message = "Requête invalide"


class ForbiddenError(ApiError):
    status_code = http.HTTP_403_FORBIDDEN
    default_message = "Accès refusé"


class NotFoundError(ApiError):
    status_code = http.HTTP_404_NOT_FOUND
    default_message = "Ressource introuvable"


class ConflictError(ApiError):
    status_code = http.HTTP_409_CONFLICT
    default_message = "Conflit avec l'état actuel de la ressource"


class BusinessValidationError(ApiError):
    status_code = http.HTTP_422_UNPROCESSABLE_ENTITY
    default_message = "Règle métier non respectée"


class BadGatewayError(ApiError):
    """Another service answered something we cannot read/understand."""
    status_code = http.HTTP_502_BAD_GATEWAY
    default_message = "Réponse illisible d'un autre service"


class ServiceUnavailableError(ApiError):
    """Another service (or RabbitMQ) is down or absent from Eureka."""
    status_code = http.HTTP_503_SERVICE_UNAVAILABLE
    default_message = "Service dépendant indisponible"


# --- Validation error flattening ---------------------------------------------

_CAMEL_RE = re.compile(r"_([a-z0-9])")


def to_camel(name: str) -> str:
    return _CAMEL_RE.sub(lambda m: m.group(1).upper(), name)


def flatten_validation_errors(detail, prefix: str = "") -> list[dict]:
    """
    Turn DRF's nested ValidationError.detail into [{"field", "message"}], with camelCase
    field paths, e.g. {"lines": [{"unit_price": ["..."]}]} -> field "lines[0].unitPrice".
    Non-field errors get field null.
    """
    out: list[dict] = []
    if isinstance(detail, dict):
        for key, value in detail.items():
            if key == api_settings.NON_FIELD_ERRORS_KEY:
                out += flatten_validation_errors(value, prefix)
            elif isinstance(key, int) or str(key).isdigit():
                # Recent DRF versions report list-item errors as {index: {...}}.
                out += flatten_validation_errors(value, f"{prefix}[{key}]")
            else:
                name = to_camel(str(key))
                out += flatten_validation_errors(value, f"{prefix}.{name}" if prefix else name)
    elif isinstance(detail, list):
        for index, item in enumerate(detail):
            if isinstance(item, (dict, list)):
                out += flatten_validation_errors(item, f"{prefix}[{index}]")
            else:
                out.append({"field": prefix or None, "message": str(item)})
    else:
        out.append({"field": prefix or None, "message": str(detail)})
    return out


# --- DRF exception handler ---------------------------------------------------

def api_exception_handler(exc, context):
    if isinstance(exc, ApiError):
        set_rollback()  # roll back the request transaction, like DRF does for its exceptions
        return Response(error_body(exc.status_code, exc.message, exc.details), status=exc.status_code)

    # DRF's handler converts Http404/PermissionDenied, sets WWW-Authenticate / Retry-After
    # headers and rolls back; we only rewrite the body.
    response = drf_exception_handler(exc, context)
    if response is None:
        return None  # unexpected error -> Django 500 (json_500 when DEBUG=False)

    if isinstance(exc, drf_exceptions.ValidationError):
        details = flatten_validation_errors(exc.detail)
        message = "La requête contient des champs invalides"
    else:
        details = []
        detail = getattr(exc, "detail", None)
        message = str(detail) if detail is not None else str(exc)
    response.data = error_body(response.status_code, message, details)
    return response


# --- Django-level handlers (non-DRF URLs) ------------------------------------

def json_404(request, exception=None):
    return JsonResponse(error_body(404, f"Aucune route pour {request.path}"), status=404)


def json_500(request):
    return JsonResponse(error_body(500, "Erreur interne du serveur"), status=500)
