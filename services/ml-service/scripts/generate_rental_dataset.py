"""Génère un historique SYNTHÉTIQUE de réservations passées -> data/synthetic_reservations.csv

Lancement (depuis services/ml-service, venv activé) :
    python -m scripts.generate_rental_dataset
    python -m scripts.generate_rental_dataset --rows 20000 --seed 7

Les règles de prix ci-dessous sont la « vérité cachée » que les modèles doivent
retrouver à partir des données. L'API ne les utilise JAMAIS directement.
"""
import argparse
from datetime import date, datetime, time, timedelta, timezone
from pathlib import Path

import numpy as np
import pandas as pd

from app.price_features import MAX_DURATION_DAYS, season_of
from app.rental_catalog import AGENCY_ID_BY_CODE, CATEGORIES, CATEGORY_ID_BY_CODE
from app.settings import SERVICE_DIR

# --- Période de l'historique simulé ---
HISTORY_START = date(2023, 1, 1)
HISTORY_END = date(2025, 12, 31)

# --- Répartition des réservations ---
AGENCY_SHARE = {"TUNIS": 0.55, "SOUSSE": 0.45}
CATEGORY_SHARE = {"ECONOMIQUE": 0.55, "SUV": 0.30, "LUXE": 0.15}

# --- Demande propre à chaque agence : taux d'occupation moyen par saison ---
# Tunis : demande régulière (affaires). Sousse : balnéaire, très saisonnière.
BASE_OCCUPANCY = {
    "TUNIS": {"BASSE": 0.60, "MOYENNE": 0.68, "HAUTE": 0.78},
    "SOUSSE": {"BASSE": 0.40, "MOYENNE": 0.65, "HAUTE": 0.92},
}
OCCUPANCY_NOISE = 0.06

# --- Règles de prix (facteurs multiplicatifs) ---
SEASON_FACTOR = {"BASSE": 0.90, "MOYENNE": 1.00, "HAUTE": 1.25}  # règle 1
LUXE_SUMMER_FACTOR = 1.10                                          # règle 2
AGENCY_FACTOR = {"TUNIS": 1.05, "SOUSSE": 1.00}                    # règle 3
OCCUPANCY_SENSITIVITY = 0.6                                        # règle 4
OCCUPANCY_REFERENCE = 0.70
WEEKDAY_FACTOR = {3: 1.03, 4: 1.08, 5: 1.08}                       # règle 5 (jeudi, vendredi, samedi)
PRICE_NOISE = 0.05                                                 # règle 8


def duration_factor(duration_days: int) -> float:
    """Règle 6 : tarif dégressif selon la durée."""
    if duration_days <= 2:
        return 1.10
    if duration_days <= 6:
        return 1.00
    if duration_days <= 13:
        return 0.90
    return 0.80


def lead_time_factor(lead_time_days: int) -> float:
    """Règle 7 : dernière minute plus chère, réservation anticipée moins chère."""
    if lead_time_days <= 2:
        return 1.15
    if lead_time_days <= 7:
        return 1.05
    if lead_time_days <= 30:
        return 1.00
    return 0.93


def compute_daily_price(category_code: str, agency_code: str, start_date: date, duration_days: int,
                        lead_time_days: int, occupancy_rate: float,
                        random_generator: np.random.Generator) -> float:
    """Prix journalier = tarif de base x facteurs x bruit."""
    season = season_of(start_date)

    price = CATEGORIES[CATEGORY_ID_BY_CODE[category_code]]["daily_rate"]          # règle 0
    price *= SEASON_FACTOR[season]                                                  # règle 1
    if category_code == "LUXE" and season == "HAUTE":
        price *= LUXE_SUMMER_FACTOR                                                 # règle 2
    price *= AGENCY_FACTOR[agency_code]                                             # règle 3
    price *= 1 + OCCUPANCY_SENSITIVITY * (occupancy_rate - OCCUPANCY_REFERENCE)     # règle 4
    price *= WEEKDAY_FACTOR.get(start_date.weekday(), 1.0)                          # règle 5
    price *= duration_factor(duration_days)                                         # règle 6
    price *= lead_time_factor(lead_time_days)                                       # règle 7
    price *= random_generator.normal(1.0, PRICE_NOISE)                              # règle 8
    return round(price, 3)


def generate_reservations(row_count: int, seed: int) -> pd.DataFrame:
    random_generator = np.random.default_rng(seed)

    # Tous les jours possibles de départ, et leur poids par agence :
    # plus l'agence est chargée à cette saison, plus il y a de départs.
    history_length = (HISTORY_END - HISTORY_START).days + 1
    possible_start_dates = [HISTORY_START + timedelta(days=offset) for offset in range(history_length)]
    start_date_probabilities = {}
    for agency_code, occupancy_by_season in BASE_OCCUPANCY.items():
        weights = np.array([occupancy_by_season[season_of(day)] for day in possible_start_dates])
        start_date_probabilities[agency_code] = weights / weights.sum()

    reservation_rows = []
    for _ in range(row_count):
        agency_code = random_generator.choice(list(AGENCY_SHARE), p=list(AGENCY_SHARE.values()))
        category_code = random_generator.choice(list(CATEGORY_SHARE), p=list(CATEGORY_SHARE.values()))
        start_index = random_generator.choice(len(possible_start_dates), p=start_date_probabilities[agency_code])
        start_date = possible_start_dates[start_index]

        # Durée : surtout courte (médiane ~4 jours), entre 1 et 30
        duration_days = int(np.clip(round(random_generator.lognormal(np.log(4), 0.6)), 1, MAX_DURATION_DAYS))
        # Délai : surtout court (moyenne ~20 jours), entre 0 et 180
        lead_time_days = int(np.clip(round(random_generator.exponential(20)), 0, 180))
        # Occupation observée = moyenne agence/saison + bruit
        occupancy_rate = float(np.clip(
            BASE_OCCUPANCY[agency_code][season_of(start_date)] + random_generator.normal(0, OCCUPANCY_NOISE),
            0.15, 0.99))
        occupancy_rate = round(occupancy_rate, 3)

        booked_date = start_date - timedelta(days=lead_time_days)
        booking_time = time(int(random_generator.integers(8, 22)), int(random_generator.integers(0, 60)))
        booked_at = datetime.combine(booked_date, booking_time, tzinfo=timezone.utc)
        daily_price = compute_daily_price(category_code, agency_code, start_date, duration_days,
                                          lead_time_days, occupancy_rate, random_generator)

        reservation_rows.append({
            "categoryId": CATEGORY_ID_BY_CODE[category_code],
            "agencyId": AGENCY_ID_BY_CODE[agency_code],
            "bookedAt": booked_at.strftime("%Y-%m-%dT%H:%M:%SZ"),
            "startDate": start_date.isoformat(),
            "endDate": (start_date + timedelta(days=duration_days)).isoformat(),
            "occupancyRate": occupancy_rate,
            "dailyPrice": daily_price,
            "totalPrice": round(daily_price * duration_days, 3),
        })

    return pd.DataFrame(reservation_rows).sort_values("bookedAt", ignore_index=True)


def main() -> None:
    parser = argparse.ArgumentParser(description="Génère le jeu de données synthétique.")
    parser.add_argument("--rows", type=int, default=10_000, help="nombre de réservations (défaut 10000)")
    parser.add_argument("--seed", type=int, default=42, help="graine du hasard (défaut 42)")
    parser.add_argument("--output", type=Path, default=SERVICE_DIR / "data" / "synthetic_reservations.csv")
    arguments = parser.parse_args()

    reservations = generate_reservations(arguments.rows, arguments.seed)
    arguments.output.parent.mkdir(parents=True, exist_ok=True)
    reservations.to_csv(arguments.output, index=False, float_format="%.3f")
    print(f"{len(reservations)} réservations écrites dans {arguments.output}")


if __name__ == "__main__":
    main()
