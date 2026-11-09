"""Génère un historique SYNTHÉTIQUE de réservations passées -> data/reservations.csv

Lancement (depuis services/ml-service, venv activé) :
    python -m scripts.generate_dataset
    python -m scripts.generate_dataset --rows 20000 --seed 7

Les règles de prix ci-dessous sont la « vérité cachée » que les modèles doivent
retrouver à partir des données. L'API ne les utilise JAMAIS directement.
"""
import argparse
from datetime import date, datetime, time, timedelta, timezone
from pathlib import Path

import numpy as np
import pandas as pd

from app.catalog import AGENCIES, CATEGORIES
from app.config import BASE_DIR
from app.ml.features import MAX_DURATION_DAYS, season_of

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


def duration_factor(days: int) -> float:
    """Règle 6 : tarif dégressif selon la durée."""
    if days <= 2:
        return 1.10
    if days <= 6:
        return 1.00
    if days <= 13:
        return 0.90
    return 0.80


def lead_time_factor(days: int) -> float:
    """Règle 7 : dernière minute plus chère, réservation anticipée moins chère."""
    if days <= 2:
        return 1.15
    if days <= 7:
        return 1.05
    if days <= 30:
        return 1.00
    return 0.93


def daily_price(category: str, agency: str, start: date, duration: int,
                lead_time: int, occupancy: float, rng: np.random.Generator) -> float:
    """Prix journalier = tarif de base x facteurs x bruit."""
    category_id = next(i for i, c in CATEGORIES.items() if c["code"] == category)
    season = season_of(start)

    price = CATEGORIES[category_id]["daily_rate"]                         # règle 0
    price *= SEASON_FACTOR[season]                                        # règle 1
    if category == "LUXE" and season == "HAUTE":
        price *= LUXE_SUMMER_FACTOR                                       # règle 2
    price *= AGENCY_FACTOR[agency]                                        # règle 3
    price *= 1 + OCCUPANCY_SENSITIVITY * (occupancy - OCCUPANCY_REFERENCE)  # règle 4
    price *= WEEKDAY_FACTOR.get(start.weekday(), 1.0)                     # règle 5
    price *= duration_factor(duration)                                    # règle 6
    price *= lead_time_factor(lead_time)                                  # règle 7
    price *= rng.normal(1.0, PRICE_NOISE)                                 # règle 8
    return round(price, 3)


def generate(rows: int, seed: int) -> pd.DataFrame:
    rng = np.random.default_rng(seed)
    agency_ids = {a["code"]: i for i, a in AGENCIES.items()}
    category_ids = {c["code"]: i for i, c in CATEGORIES.items()}

    # Tous les jours possibles de départ, et leur poids par agence :
    # plus l'agence est chargée à cette saison, plus il y a de départs.
    days = [HISTORY_START + timedelta(n) for n in range((HISTORY_END - HISTORY_START).days + 1)]
    day_weights = {}
    for agency, by_season in BASE_OCCUPANCY.items():
        w = np.array([by_season[season_of(d)] for d in days])
        day_weights[agency] = w / w.sum()

    records = []
    for _ in range(rows):
        agency = rng.choice(list(AGENCY_SHARE), p=list(AGENCY_SHARE.values()))
        category = rng.choice(list(CATEGORY_SHARE), p=list(CATEGORY_SHARE.values()))
        start = days[rng.choice(len(days), p=day_weights[agency])]

        # Durée : surtout courte (médiane ~4 jours), entre 1 et 30
        duration = int(np.clip(round(rng.lognormal(np.log(4), 0.6)), 1, MAX_DURATION_DAYS))
        # Délai : surtout court (moyenne ~20 jours), entre 0 et 180
        lead_time = int(np.clip(round(rng.exponential(20)), 0, 180))
        # Occupation observée = moyenne agence/saison + bruit
        occupancy = float(np.clip(
            BASE_OCCUPANCY[agency][season_of(start)] + rng.normal(0, OCCUPANCY_NOISE), 0.15, 0.99))
        occupancy = round(occupancy, 3)

        booked_day = start - timedelta(days=lead_time)
        booked_at = datetime.combine(booked_day, time(int(rng.integers(8, 22)), int(rng.integers(0, 60))),
                                     tzinfo=timezone.utc)
        price = daily_price(category, agency, start, duration, lead_time, occupancy, rng)

        records.append({
            "categoryId": category_ids[category],
            "agencyId": agency_ids[agency],
            "bookedAt": booked_at.strftime("%Y-%m-%dT%H:%M:%SZ"),
            "startDate": start.isoformat(),
            "endDate": (start + timedelta(days=duration)).isoformat(),
            "occupancyRate": occupancy,
            "dailyPrice": price,
            "totalPrice": round(price * duration, 3),
        })

    return pd.DataFrame(records).sort_values("bookedAt", ignore_index=True)


def main() -> None:
    parser = argparse.ArgumentParser(description="Génère le jeu de données synthétique.")
    parser.add_argument("--rows", type=int, default=10_000, help="nombre de réservations (défaut 10000)")
    parser.add_argument("--seed", type=int, default=42, help="graine du hasard (défaut 42)")
    parser.add_argument("--output", type=Path, default=BASE_DIR / "data" / "reservations.csv")
    args = parser.parse_args()

    df = generate(args.rows, args.seed)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    df.to_csv(args.output, index=False, float_format="%.3f")
    print(f"{len(df)} réservations écrites dans {args.output}")


if __name__ == "__main__":
    main()
