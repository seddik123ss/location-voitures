"""Prédiction du prix journalier : règles métier, calcul des variables, modèle, intervalle, log.

Utilisé par les deux routes : /api/ml/price-predictions et /internal/ml/price-predictions.
"""
from datetime import date, datetime, timezone

import pandas as pd
from sqlalchemy import select
from sqlalchemy.orm import Session

from app import model_training
from app.api_schemas import PricePredictionRequest, PricePredictionResponse
from app.db_tables import ModelVersion, PredictionLog
from app.error_handlers import BusinessValidationError, NoActiveModelError
from app.price_features import MAX_DURATION_DAYS, build_features, season_of
from app.rental_catalog import AGENCIES, CATEGORIES

MAX_LEAD_TIME_DAYS = 180     # le modèle n'a jamais vu de réservation faite plus de 180 jours à l'avance
CURRENCY = "TND"
AMOUNT_DECIMALS = 3          # convention de l'équipe

# Modèles déjà chargés en mémoire : numéro de version -> bundle (évite de relire le fichier à chaque requête)
_loaded_bundles: dict[int, dict] = {}


def today_utc() -> date:
    """Date du jour (UTC). Fonction séparée pour pouvoir la remplacer dans les tests."""
    return datetime.now(timezone.utc).date()


# ---------- 1. Règles métier ----------

def validate_prediction_request(request: PricePredictionRequest, today: date) -> None:
    """Vérifie TOUTES les règles et lève une seule erreur 422 qui liste tous les problèmes."""
    problems = []

    if str(request.category_id) not in CATEGORIES:
        problems.append({"field": "categoryId", "message": "catégorie inconnue"})
    if str(request.agency_id) not in AGENCIES:
        problems.append({"field": "agencyId", "message": "agence inconnue"})

    if request.start_date < today:
        problems.append({"field": "startDate", "message": "la date de départ est dans le passé"})
    elif (request.start_date - today).days > MAX_LEAD_TIME_DAYS:
        problems.append({"field": "startDate",
                         "message": f"la date de départ doit être dans les {MAX_LEAD_TIME_DAYS} prochains jours"})

    duration_days = (request.end_date - request.start_date).days
    if duration_days < 1:
        problems.append({"field": "endDate", "message": "la date de retour doit être après la date de départ"})
    elif duration_days > MAX_DURATION_DAYS:
        problems.append({"field": "endDate", "message": f"durée maximale : {MAX_DURATION_DAYS} jours"})

    if request.occupancy_rate is not None and not 0 <= request.occupancy_rate <= 1:
        problems.append({"field": "occupancyRate", "message": "doit être compris entre 0 et 1"})

    if problems:
        raise BusinessValidationError("Demande de prédiction invalide", details=problems)


# ---------- 2. Modèle actif ----------

def find_active_model_version(session: Session) -> ModelVersion:
    """La version active en base, sinon 503 (le service ne peut pas répondre)."""
    active_version = session.scalars(select(ModelVersion).where(ModelVersion.active)).first()
    if active_version is None:
        raise NoActiveModelError("Aucun modèle actif : activez une version avec POST /api/ml/models/{id}/activate")
    return active_version


def get_model_bundle(version: int) -> dict:
    """Bundle de la version demandée, lu sur disque la première fois puis gardé en mémoire."""
    if version not in _loaded_bundles:
        if not model_training.model_file_path(version).exists():
            raise NoActiveModelError(f"Fichier du modèle v{version} introuvable")
        _loaded_bundles[version] = model_training.load_model_bundle(version)
    return _loaded_bundles[version]


def forget_model_bundle(version: int) -> None:
    """Retire une version de la mémoire (appelé quand elle est supprimée)."""
    _loaded_bundles.pop(version, None)


def estimate_occupancy_rate(model_bundle: dict, agency_id: str, start_date: date) -> float:
    """Taux d'occupation moyen historique pour l'agence et la saison du départ."""
    agency_code = AGENCIES[agency_id]["code"]
    return model_bundle["occupancy_table"][agency_code][season_of(start_date)]


# ---------- 3. Prédiction complète ----------

def predict_price(session: Session, request: PricePredictionRequest) -> PricePredictionResponse:
    today = today_utc()
    validate_prediction_request(request, today)                              # 422 si invalide

    active_version = find_active_model_version(session)                     # 503 si aucun
    model_bundle = get_model_bundle(active_version.version)

    category_id, agency_id = str(request.category_id), str(request.agency_id)
    occupancy_rate = request.occupancy_rate
    if occupancy_rate is None:
        occupancy_rate = estimate_occupancy_rate(model_bundle, agency_id, request.start_date)

    # Même calcul qu'à l'entraînement ; la date de réservation est aujourd'hui
    features = build_features(category_id, agency_id, request.start_date, request.end_date,
                              booked_on=today, occupancy_rate=occupancy_rate)
    predicted_price = float(model_bundle["pipeline"].predict(pd.DataFrame([features]))[0])

    interval_ratios = model_bundle["interval_ratios"]
    response = PricePredictionResponse(
        daily_price=round(predicted_price, AMOUNT_DECIMALS),
        lower_bound=round(predicted_price * interval_ratios["lower_ratio"], AMOUNT_DECIMALS),
        upper_bound=round(predicted_price * interval_ratios["upper_ratio"], AMOUNT_DECIMALS),
        currency=CURRENCY,
        model_version=active_version.version,
    )

    save_prediction_log(session, request, features, response, active_version)
    return response


def save_prediction_log(session: Session, request: PricePredictionRequest, features: dict,
                        response: PricePredictionResponse, model_version: ModelVersion) -> None:
    """Enregistre la demande, les variables calculées et la réponse dans prediction_logs."""
    session.add(PredictionLog(
        input={"request": request.model_dump(mode="json", by_alias=True), "features": features},
        output=response.model_dump(mode="json", by_alias=True),
        model_version_id=model_version.id,
    ))
    session.commit()
