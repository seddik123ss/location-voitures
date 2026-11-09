"""Textes de la documentation Swagger (OpenAPI) : groupes, descriptions, exemples, réponses d'erreur.

Rangés ici pour que les fichiers de routes restent courts et lisibles.
Les exemples d'erreur reprennent les vraies réponses du service.
"""
from datetime import date, timedelta

from app.api_schemas import ErrorResponse

# ---------- Groupes d'endpoints (tags) ----------

OPENAPI_TAGS = [
    {"name": "Prédiction de prix", "description": "Prix journalier conseillé par le modèle actif."},
    {"name": "Versions du modèle", "description": "Consulter, renommer, supprimer et activer les versions."},
    {"name": "Entraînement", "description": "Réentraîner le modèle et créer une nouvelle version."},
    {"name": "Interne (entre services)",
     "description": "Routes /internal : appelées directement par les autres services, jamais via la Gateway."},
    {"name": "Santé", "description": "État du service."},
]

ROLES_NOTE = "\n\n**Rôle requis** (token Keycloak) : {roles}."


# ---------- Construction des réponses d'erreur ----------

def error_response(description: str, status: int, error: str, message: str,
                   details: list[dict] | None = None) -> dict:
    """Une réponse d'erreur documentée : schéma commun + exemple réel."""
    return {
        "model": ErrorResponse,
        "description": description,
        "content": {"application/json": {"example": {
            "status": status, "error": error, "message": message, "details": details or []}}},
    }


INVALID_JSON = error_response(
    "Corps de requête illisible (JSON invalide)", 400, "BAD_REQUEST",
    "Corps de requête illisible : JSON invalide")
INVALID_ID = error_response(
    "Identifiant de version qui n'est pas un UUID", 400, "BAD_REQUEST",
    "Paramètres de la requête invalides", [{"field": "id", "message": "UUID invalide"}])
INVALID_PAGINATION = error_response(
    "page < 0 ou size hors de 1..100", 400, "BAD_REQUEST", "Paramètres de la requête invalides",
    [{"field": "page", "message": "valeur trop petite"}, {"field": "size", "message": "valeur trop grande"}])
MODEL_NOT_FOUND = error_response(
    "Version introuvable", 404, "NOT_FOUND",
    "Version de modèle 11111111-1111-4111-8111-111111111111 introuvable")
PREDICTION_INVALID = error_response(
    "Demande invalide : toutes les erreurs sont listées dans details (une par champ)", 422,
    "UNPROCESSABLE_ENTITY", "Demande de prédiction invalide", [
        {"field": "categoryId", "message": "catégorie inconnue"},
        {"field": "startDate", "message": "la date de départ est dans le passé"},
        {"field": "endDate", "message": "la date de retour doit être après la date de départ"},
        {"field": "occupancyRate", "message": "doit être compris entre 0 et 1"},
    ])
NO_ACTIVE_MODEL = error_response(
    "Aucune version active (ou Keycloak injoignable) : le service ne peut pas calculer de prix",
    503, "SERVICE_UNAVAILABLE",
    "Aucun modèle actif : activez une version avec POST /api/ml/models/{id}/activate")
UPDATE_INVALID = error_response(
    "Nom vide ou champ non modifiable envoyé (seul name est modifiable)", 422, "UNPROCESSABLE_ENTITY",
    "Données de la requête invalides", [{"field": "active", "message": "champ non autorisé"}])
DELETE_ACTIVE_CONFLICT = error_response(
    "La version est active : en activer une autre avant de la supprimer", 409, "CONFLICT",
    "Impossible de supprimer la version active : activez d'abord une autre version")
ACTIVATE_FILE_CONFLICT = error_response(
    "Le fichier .joblib de cette version est introuvable", 409, "CONFLICT",
    "Fichier du modèle v2 introuvable : activation impossible")

PREDICTION_ERRORS = {400: INVALID_JSON, 422: PREDICTION_INVALID, 503: NO_ACTIVE_MODEL}

# Erreurs de sécurité, déclarées une fois par router (toutes les routes protégées)
UNAUTHORIZED = error_response(
    "Token absent, expiré ou invalide", 401, "UNAUTHORIZED",
    "Token absent : en-tête Authorization: Bearer <token> attendu")
FORBIDDEN_ADMIN = error_response(
    "Token valide mais sans le rôle ADMIN", 403, "FORBIDDEN", "Rôle insuffisant : ADMIN requis")
FORBIDDEN_INTERNAL = error_response(
    "Token valide mais sans le rôle INTERNAL", 403, "FORBIDDEN", "Rôle insuffisant : INTERNAL requis")
KEYCLOAK_UNAVAILABLE = error_response(
    "Keycloak injoignable : le token ne peut pas être vérifié", 503, "SERVICE_UNAVAILABLE",
    "Keycloak injoignable : impossible de vérifier le token")
ADMIN_SECURITY_ERRORS = {401: UNAUTHORIZED, 403: FORBIDDEN_ADMIN, 503: KEYCLOAK_UNAVAILABLE}
INTERNAL_SECURITY_ERRORS = {401: UNAUTHORIZED, 403: FORBIDDEN_INTERNAL, 503: KEYCLOAK_UNAVAILABLE}


# ---------- Prédiction : description et exemples d'entrée ----------

PREDICTION_DESCRIPTION = """
Calcule le **prix journalier conseillé** (TND, 3 décimales) et un **intervalle à 90 %**.

Le service calcule lui-même la saison, le jour de départ, la durée et le délai de réservation
(départ − aujourd'hui). Si `occupancyRate` est absent, il est **estimé** par la moyenne historique
de l'agence pour la saison du départ.

**Règles (422)** — toutes les erreurs sont renvoyées ensemble dans `details` :
- `categoryId` / `agencyId` inconnus du catalogue ;
- `startDate` dans le passé, ou plus de 180 jours dans le futur ;
- `endDate` pas après `startDate`, ou durée de plus de 30 jours ;
- `occupancyRate` hors de l'intervalle 0–1.

**503** si aucune version du modèle n'est active. Chaque prédiction est enregistrée (prediction_logs).
"""


def _in_days(days: int) -> str:
    """Date AAAA-MM-JJ dans N jours : les exemples restent valables quel que soit le jour."""
    return (date.today() + timedelta(days=days)).isoformat()


SUV_ID, SOUSSE_ID = "a6ec349d-0ff0-4cb1-bfb9-453dd8e2ee8c", "1deb1dea-ab54-436d-8ed7-c3322f782c88"
LUXE_ID, TUNIS_ID = "98d95d25-4112-4e52-8fbf-d2d908239b34", "eb825ac4-855b-499b-b37a-b4c37bbd6b4e"

PREDICTION_REQUEST_EXAMPLES = {
    "occupation_estimee": {
        "summary": "SUV à Sousse, occupation estimée",
        "description": "Cas courant : l'appelant ne connaît pas le taux d'occupation.",
        "value": {"categoryId": SUV_ID, "agencyId": SOUSSE_ID,
                  "startDate": _in_days(30), "endDate": _in_days(34)},
    },
    "occupation_fournie": {
        "summary": "Luxe à Tunis, occupation fournie (0.95)",
        "value": {"categoryId": LUXE_ID, "agencyId": TUNIS_ID,
                  "startDate": _in_days(10), "endDate": _in_days(12), "occupancyRate": 0.95},
    },
    "demande_invalide": {
        "summary": "Demande invalide (→ 422 avec plusieurs erreurs)",
        "value": {"categoryId": "00000000-0000-4000-8000-000000000000", "agencyId": SOUSSE_ID,
                  "startDate": _in_days(-5), "endDate": _in_days(-8), "occupancyRate": 1.5},
    },
}


# ---------- Versions du modèle et entraînement ----------

LIST_DESCRIPTION = "Liste paginée des versions, la plus récente d'abord." + ROLES_NOTE.format(roles="ADMIN")
GET_DESCRIPTION = "Détail d'une version : métriques mesurées sur le jeu de test." + ROLES_NOTE.format(roles="ADMIN")
UPDATE_DESCRIPTION = (
    "Seul `name` est modifiable. `version`, `metrics` et `trainedAt` sont en lecture seule ; "
    "`active` se change uniquement avec `/activate`." + ROLES_NOTE.format(roles="ADMIN"))
DELETE_DESCRIPTION = (
    "Supprime une version **inactive** et son fichier `.joblib`. Les logs de prédiction sont conservés "
    "(lien mis à NULL ; le numéro reste dans leur sortie). **409** si la version est active."
    + ROLES_NOTE.format(roles="ADMIN"))
ACTIVATE_DESCRIPTION = (
    "Rend cette version active et désactive l'ancienne dans **une seule transaction** "
    "(une seule version active, garanti aussi par un index unique en base)." + ROLES_NOTE.format(roles="ADMIN"))
TRAINING_DESCRIPTION = """
Réentraîne le modèle (environ 2 s) sur l'historique synthétique **et les réservations terminées**
reçues par RabbitMQ (file ml.reservation_completed) : découpage **60/20/20**, entraînement de LinearRegression,
RandomForest et XGBoost, choix du meilleur et calcul de l'intervalle sur la **validation**,
métriques finales mesurées sur le **test**.

La nouvelle version est créée **inactive** (à activer après vérification des métriques).
Son numéro vient d'une séquence PostgreSQL : il n'est **jamais réutilisé**, même après une suppression.
""" + ROLES_NOTE.format(roles="ADMIN")
TRAINING_CREATED = {
    "description": "Version créée (inactive)",
    "headers": {"Location": {"description": "URL de la nouvelle version : /api/ml/models/{id}",
                             "schema": {"type": "string"}}},
}

INTERNAL_PREDICTION_DESCRIPTION = (
    "Même calcul et mêmes règles que `POST /api/ml/price-predictions`, pour reservation-service. "
    "Contrat : docs/internal-api.md." + ROLES_NOTE.format(roles="INTERNAL (token de compte de service)"))
PUBLIC_PREDICTION_DESCRIPTION = PREDICTION_DESCRIPTION + ROLES_NOTE.format(roles="ADMIN")

HEALTH_RESPONSE = {200: {"description": "Service démarré",
                         "content": {"application/json": {"example": {"status": "UP"}}}}}


# ---------- Nettoyage du document généré ----------

FASTAPI_VALIDATION_SCHEMAS = ("HTTPValidationError", "ValidationError")


def remove_default_validation_errors(openapi_schema: dict) -> dict:
    """FastAPI ajoute d'office une réponse « 422 Validation Error » (format HTTPValidationError)
    à chaque route qui a des paramètres. Chez nous, ces erreurs sont converties au format commun
    par error_handlers.py (400 pour l'URL, 422 pour le corps) : on retire donc ces 422 automatiques
    et leurs schémas. Les 422 déclarées par nous (ErrorResponse) sont gardées."""
    for operations in openapi_schema.get("paths", {}).values():
        for operation in operations.values():
            response_422 = operation.get("responses", {}).get("422", {})
            if "HTTPValidationError" in str(response_422):
                del operation["responses"]["422"]
    schemas = openapi_schema.get("components", {}).get("schemas", {})
    for schema_name in FASTAPI_VALIDATION_SCHEMAS:
        schemas.pop(schema_name, None)
    return openapi_schema
