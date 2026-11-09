"""Règles de calcul des variables du modèle (app/price_features.py) et générateur de données."""
from datetime import date

import pandas as pd
from pandas.testing import assert_frame_equal

from app.price_features import build_features, features_from_history, season_of
from scripts.generate_rental_dataset import generate_reservations

SUV_ID = "a6ec349d-0ff0-4cb1-bfb9-453dd8e2ee8c"
SOUSSE_ID = "1deb1dea-ab54-436d-8ed7-c3322f782c88"


def test_season_depends_on_departure_month():
    assert season_of(date(2026, 1, 15)) == "BASSE"
    assert season_of(date(2026, 7, 1)) == "HAUTE"
    assert season_of(date(2026, 8, 31)) == "HAUTE"
    assert season_of(date(2026, 10, 10)) == "MOYENNE"
    assert season_of(date(2026, 12, 18)) == "MOYENNE"


def test_build_features_computes_duration_lead_time_and_weekday():
    features = build_features(SUV_ID, SOUSSE_ID, start_date=date(2026, 12, 18), end_date=date(2026, 12, 22),
                              booked_on=date(2026, 11, 9), occupancy_rate=0.8)

    assert features == {
        "category": "SUV",
        "agency": "SOUSSE",
        "season": "MOYENNE",
        "start_weekday": 4,          # 18/12/2026 est un vendredi (0 = lundi)
        "duration_days": 4,          # endDate - startDate
        "lead_time_days": 39,        # startDate - date de réservation
        "occupancy_rate": 0.8,
    }


def test_history_and_single_reservation_give_identical_features():
    """Règle d'or : même calcul à l'entraînement (tableau) et à la prédiction (une réservation)."""
    raw_reservation = pd.DataFrame([{
        "categoryId": SUV_ID, "agencyId": SOUSSE_ID, "bookedAt": "2026-11-09T10:12:00Z",
        "startDate": "2026-12-18", "endDate": "2026-12-22", "occupancyRate": 0.8,
    }])
    from_history = features_from_history(raw_reservation).iloc[0].to_dict()
    single = build_features(SUV_ID, SOUSSE_ID, date(2026, 12, 18), date(2026, 12, 22), date(2026, 11, 9), 0.8)

    assert {key: (value.item() if hasattr(value, "item") else value) for key, value in from_history.items()} == single


def test_generated_dataset_is_reproducible_with_same_seed():
    first_generation = generate_reservations(300, seed=42)
    second_generation = generate_reservations(300, seed=42)

    assert_frame_equal(first_generation, second_generation)
    assert first_generation["dailyPrice"].gt(0).all()
