"""Calcul des variables du modèle à partir des données brutes d'une réservation.

Ce module est utilisé PARTOUT (générateur, notebook, entraînement, API) :
le calcul est donc strictement identique à l'entraînement et à la prédiction.
"""
from datetime import date

import pandas as pd

from app.rental_catalog import AGENCIES, CATEGORIES

# Durée maximale d'une location acceptée par le service (en jours)
MAX_DURATION_DAYS = 30

# Saison selon le mois du départ (1 = janvier ... 12 = décembre)
SEASON_BY_MONTH = {
    1: "BASSE", 2: "BASSE", 3: "BASSE",
    4: "MOYENNE", 5: "MOYENNE", 6: "MOYENNE",
    7: "HAUTE", 8: "HAUTE",
    9: "MOYENNE", 10: "MOYENNE",
    11: "BASSE", 12: "MOYENNE",
}

# Colonnes données au modèle. Le jour de la semaine (0 = lundi ... 6 = dimanche)
# est traité comme une catégorie : son effet sur le prix n'est pas "linéaire".
CATEGORICAL_FEATURES = ["category", "agency", "season", "start_weekday"]
NUMERIC_FEATURES = ["duration_days", "lead_time_days", "occupancy_rate"]
FEATURE_COLUMNS = CATEGORICAL_FEATURES + NUMERIC_FEATURES
TARGET_COLUMN = "daily_price"


def season_of(day: date) -> str:
    """Saison (BASSE, MOYENNE, HAUTE) d'une date."""
    return SEASON_BY_MONTH[day.month]


def build_features(
    category_id: str,
    agency_id: str,
    start_date: date,
    end_date: date,
    booked_on: date,
    occupancy_rate: float,
) -> dict:
    """Variables d'UNE réservation (utilisé par l'API pour une prédiction).

    booked_on = date de la réservation (aujourd'hui pour une prédiction).
    """
    return {
        "category": CATEGORIES[category_id]["code"],
        "agency": AGENCIES[agency_id]["code"],
        "season": season_of(start_date),
        "start_weekday": start_date.weekday(),
        "duration_days": (end_date - start_date).days,
        "lead_time_days": (start_date - booked_on).days,
        "occupancy_rate": occupancy_rate,
    }


def features_from_history(raw_reservations: pd.DataFrame) -> pd.DataFrame:
    """Transforme l'historique brut (format ReservationCompleted + occupancyRate + dailyPrice)
    en tableau de variables + colonne cible daily_price. Même calcul que build_features."""
    start_dates = pd.to_datetime(raw_reservations["startDate"])
    end_dates = pd.to_datetime(raw_reservations["endDate"])
    # bookedAt est un instant UTC : on garde seulement la date
    booked_dates = (
        pd.to_datetime(raw_reservations["bookedAt"], utc=True).dt.tz_localize(None).dt.normalize()
    )

    features = pd.DataFrame({
        "category": raw_reservations["categoryId"].map(lambda category_id: CATEGORIES[category_id]["code"]),
        "agency": raw_reservations["agencyId"].map(lambda agency_id: AGENCIES[agency_id]["code"]),
        "season": start_dates.dt.month.map(SEASON_BY_MONTH),
        "start_weekday": start_dates.dt.day_of_week,   # 0 = lundi, comme date.weekday()
        "duration_days": (end_dates - start_dates).dt.days,
        "lead_time_days": (start_dates - booked_dates).dt.days,
        "occupancy_rate": raw_reservations["occupancyRate"].astype(float),
    })
    if "dailyPrice" in raw_reservations.columns:
        features[TARGET_COLUMN] = raw_reservations["dailyPrice"].astype(float)
    return features
