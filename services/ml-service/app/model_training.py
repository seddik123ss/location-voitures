"""Briques de l'entraînement du modèle de prix (utilisées par le notebook et, plus tard, par l'API).

Chaque fonction fait UNE chose. L'enchaînement complet est montré dans
notebooks/price_model_training.ipynb.
"""
from datetime import datetime, timezone
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.ensemble import RandomForestRegressor
from sklearn.linear_model import LinearRegression
from sklearn.metrics import mean_absolute_error, r2_score
from sklearn.model_selection import train_test_split
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder, StandardScaler
from xgboost import XGBRegressor

from app.price_features import (
    CATEGORICAL_FEATURES, FEATURE_COLUMNS, NUMERIC_FEATURES, TARGET_COLUMN, features_from_history,
)
from app.settings import SERVICE_DIR

DATASET_PATH = SERVICE_DIR / "data" / "synthetic_reservations.csv"
ARTIFACTS_DIR = SERVICE_DIR / "artifacts"
RANDOM_SEED = 42

# Les 3 modèles comparés : nom lisible -> fonction qui crée un modèle neuf
CANDIDATE_MODELS = {
    "LinearRegression": lambda: LinearRegression(),
    "RandomForest": lambda: RandomForestRegressor(
        n_estimators=150, min_samples_leaf=5, n_jobs=-1, random_state=RANDOM_SEED),
    "XGBoost": lambda: XGBRegressor(
        n_estimators=500, learning_rate=0.05, max_depth=5, subsample=0.8,
        colsample_bytree=0.8, random_state=RANDOM_SEED),
}


def load_feature_table(csv_path: Path = DATASET_PATH) -> pd.DataFrame:
    """Lit l'historique brut (CSV) et le transforme en variables + prix cible."""
    raw_reservations = pd.read_csv(csv_path)
    return features_from_history(raw_reservations)


def split_train_validation_test(feature_table: pd.DataFrame,
                                validation_share: float = 0.2, test_share: float = 0.2) -> dict:
    """Sépare en 3 jeux (par défaut 60 % / 20 % / 20 %) :
    - train      : pour entraîner les modèles ;
    - validation : pour choisir le modèle et calculer les ratios de l'intervalle ;
    - test       : mis de côté jusqu'à la mesure finale (MAE, R², couverture).
    Retourne {"train": (features, prices), "validation": (...), "test": (...)}."""
    all_features = feature_table[FEATURE_COLUMNS]
    all_prices = feature_table[TARGET_COLUMN]

    # 1) on retire d'abord le test (20 % du total)
    remaining_features, test_features, remaining_prices, test_prices = train_test_split(
        all_features, all_prices, test_size=test_share, random_state=RANDOM_SEED)
    # 2) puis on coupe le reste en train / validation (20 % du total = 25 % du reste)
    validation_share_of_remaining = validation_share / (1 - test_share)
    train_features, validation_features, train_prices, validation_prices = train_test_split(
        remaining_features, remaining_prices, test_size=validation_share_of_remaining,
        random_state=RANDOM_SEED)

    return {
        "train": (train_features, train_prices),
        "validation": (validation_features, validation_prices),
        "test": (test_features, test_prices),
    }


def build_preprocessor() -> ColumnTransformer:
    """Préparation des colonnes : catégories -> colonnes 0/1, nombres -> mis à la même échelle."""
    return ColumnTransformer([
        ("categories_to_columns", OneHotEncoder(handle_unknown="ignore", sparse_output=False),
         CATEGORICAL_FEATURES),
        ("numbers_scaled", StandardScaler(), NUMERIC_FEATURES),
    ])


def build_pipeline(model_name: str) -> Pipeline:
    """Pipeline complet = préparation des colonnes puis modèle de régression."""
    return Pipeline([
        ("preparation", build_preprocessor()),
        ("regressor", CANDIDATE_MODELS[model_name]()),
    ])


def train_model(model_name: str, train_features: pd.DataFrame, train_prices: pd.Series) -> Pipeline:
    """Crée le pipeline du modèle demandé et l'entraîne."""
    pipeline = build_pipeline(model_name)
    pipeline.fit(train_features, train_prices)
    return pipeline


def evaluate_model(pipeline: Pipeline, features: pd.DataFrame, actual_prices: pd.Series) -> dict:
    """MAE = erreur moyenne en TND ; R² = part de la variation des prix expliquée (1 = parfait).
    Utilisé sur la validation (pour choisir) puis sur le test (mesure finale)."""
    predicted_prices = pipeline.predict(features)
    return {
        "mae": round(float(mean_absolute_error(actual_prices, predicted_prices)), 3),
        "r2": round(float(r2_score(actual_prices, predicted_prices)), 4),
    }


def compute_interval_ratios(pipeline: Pipeline, validation_features: pd.DataFrame,
                            validation_prices: pd.Series,
                            lower_quantile: float = 0.05, upper_quantile: float = 0.95) -> dict:
    """Intervalle de prix à partir des erreurs RELATIVES sur le jeu de VALIDATION.

    ratio = prix réel / prix prédit. Avec les quantiles 5 % et 95 % de ce ratio,
    l'intervalle [prédit x ratio_bas ; prédit x ratio_haut] devrait contenir ~90 % des prix réels
    (à vérifier ensuite sur le test, avec measure_interval_coverage).
    """
    price_ratios = np.asarray(validation_prices) / pipeline.predict(validation_features)
    return {
        "lower_ratio": round(float(np.quantile(price_ratios, lower_quantile)), 4),
        "upper_ratio": round(float(np.quantile(price_ratios, upper_quantile)), 4),
        "confidence": round(upper_quantile - lower_quantile, 2),
    }


def measure_interval_coverage(pipeline: Pipeline, interval_ratios: dict,
                              test_features: pd.DataFrame, test_prices: pd.Series) -> float:
    """Part des prix réels du test qui tombent bien dans l'intervalle (vérification)."""
    predicted_prices = pipeline.predict(test_features)
    lower_bounds = predicted_prices * interval_ratios["lower_ratio"]
    upper_bounds = predicted_prices * interval_ratios["upper_ratio"]
    actual_prices = np.asarray(test_prices)
    inside_interval = (actual_prices >= lower_bounds) & (actual_prices <= upper_bounds)
    return round(float(inside_interval.mean()), 4)


def compute_occupancy_table(train_features: pd.DataFrame) -> dict:
    """Taux d'occupation moyen par agence et saison, ex. {"SOUSSE": {"HAUTE": 0.92, ...}}.
    Sert d'estimation quand l'appelant ne fournit pas occupancyRate."""
    mean_occupancy = train_features.groupby(["agency", "season"])["occupancy_rate"].mean().round(3)
    occupancy_table: dict = {}
    for (agency_code, season), occupancy_rate in mean_occupancy.items():
        occupancy_table.setdefault(agency_code, {})[season] = float(occupancy_rate)
    return occupancy_table


def create_model_bundle(model_name: str, version: int, pipeline: Pipeline, metrics: dict,
                        interval_ratios: dict, occupancy_table: dict, training_rows: int) -> dict:
    """Regroupe tout ce dont l'API a besoin pour prédire dans un seul objet."""
    return {
        "model_name": model_name,
        "version": version,
        "pipeline": pipeline,
        "metrics": metrics,
        "interval_ratios": interval_ratios,
        "occupancy_table": occupancy_table,
        "feature_columns": FEATURE_COLUMNS,
        "training_rows": training_rows,
        "trained_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }


def model_file_path(version: int) -> Path:
    """Chemin du fichier d'une version : artifacts/price_model_v<N>.joblib"""
    return ARTIFACTS_DIR / f"price_model_v{version}.joblib"


def save_model_bundle(model_bundle: dict) -> Path:
    """Sauvegarde le bundle avec joblib (compressé) et retourne le chemin du fichier."""
    ARTIFACTS_DIR.mkdir(parents=True, exist_ok=True)
    file_path = model_file_path(model_bundle["version"])
    joblib.dump(model_bundle, file_path, compress=3)
    return file_path


def load_model_bundle(version: int) -> dict:
    """Recharge un bundle sauvegardé."""
    return joblib.load(model_file_path(version))


def train_new_model_version(version: int, csv_path: Path = DATASET_PATH) -> dict:
    """Enchaîne toutes les briques, comme le notebook (utilisé par POST /api/ml/trainings) :
    découpage 60/20/20, entraînement des 3 modèles, choix et intervalle sur la validation,
    mesure finale sur le test, sauvegarde. Retourne le bundle sauvegardé."""
    data_splits = split_train_validation_test(load_feature_table(csv_path))
    train_features, train_prices = data_splits["train"]
    validation_features, validation_prices = data_splits["validation"]
    test_features, test_prices = data_splits["test"]

    trained_pipelines = {name: train_model(name, train_features, train_prices) for name in CANDIDATE_MODELS}
    validation_mae_by_model = {
        name: evaluate_model(pipeline, validation_features, validation_prices)["mae"]
        for name, pipeline in trained_pipelines.items()}
    best_model_name = min(validation_mae_by_model, key=validation_mae_by_model.get)
    best_pipeline = trained_pipelines[best_model_name]

    interval_ratios = compute_interval_ratios(best_pipeline, validation_features, validation_prices)
    test_metrics = evaluate_model(best_pipeline, test_features, test_prices)
    test_metrics["interval_coverage"] = measure_interval_coverage(
        best_pipeline, interval_ratios, test_features, test_prices)
    test_metrics["validation_mae"] = validation_mae_by_model[best_model_name]

    model_bundle = create_model_bundle(
        model_name=best_model_name, version=version, pipeline=best_pipeline, metrics=test_metrics,
        interval_ratios=interval_ratios, occupancy_table=compute_occupancy_table(train_features),
        training_rows=len(train_features))
    save_model_bundle(model_bundle)
    return model_bundle
