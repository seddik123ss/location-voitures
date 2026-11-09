"""Fichiers des modèles entraînés : artifacts/price_model_v<N>.joblib

Un « bundle » = un dictionnaire qui contient tout ce dont l'API a besoin pour prédire :
le pipeline entraîné, le nom du modèle, la version, les métriques, les ratios de l'intervalle,
le tableau d'occupation, les colonnes et la date d'entraînement.
"""
from datetime import datetime, timezone
from pathlib import Path

import joblib
from sklearn.pipeline import Pipeline

from app.price_features import FEATURE_COLUMNS
from app.settings import SERVICE_DIR

ARTIFACTS_DIR = SERVICE_DIR / "artifacts"


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
