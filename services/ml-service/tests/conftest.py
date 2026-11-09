"""Préparation commune des tests (fixtures pytest).

Garanties :
- les tests utilisent la base ml_test_db, JAMAIS ml_db (sécurité qui arrête tout sinon) ;
- les modèles sont lus et écrits dans un dossier temporaire, jamais dans artifacts/ ;
- chaque test démarre du même état : v1 active, séquence à 2, aucun log ;
- la date du jour est fixée au 2026-11-09 pour que les règles de dates soient stables.

Création de la base de test (une seule fois) :
    docker exec location-voitures-postgres-1 psql -U postgres -c "CREATE DATABASE ml_test_db OWNER ml_svc;"
"""
import json
import os
import shutil
import time
from datetime import date

import pytest

# 1) AVANT d'importer l'application : on la branche sur la base de TEST.
#    Une variable d'environnement passe avant le fichier .env pour pydantic-settings.
TEST_DB_URL = os.environ.get("TEST_DB_URL", "postgresql+psycopg://ml_svc:ml_pwd@localhost:5432/ml_test_db")
if "test" not in TEST_DB_URL.rsplit("/", 1)[-1]:
    raise RuntimeError(f"Sécurité : la base de test doit contenir « test » dans son nom ({TEST_DB_URL})")
os.environ["DB_URL"] = TEST_DB_URL
# Les tests ne s'inscrivent jamais dans Eureka (le TestClient déclenche le démarrage de l'application)
os.environ["EUREKA_ENABLED"] = "false"
# ... ni ne consomment de messages RabbitMQ (la logique est testée sans RabbitMQ)
os.environ["RABBITMQ_ENABLED"] = "false"

import jwt  # noqa: E402  (imports après le réglage de DB_URL, volontairement)
from alembic import command  # noqa: E402
from alembic.config import Config  # noqa: E402
from cryptography.hazmat.primitives.asymmetric import rsa  # noqa: E402
from fastapi.testclient import TestClient  # noqa: E402
from sqlalchemy import text  # noqa: E402

from app import keycloak_security, model_artifacts, model_training, price_predictor  # noqa: E402
from app.database import engine  # noqa: E402
from app.settings import SERVICE_DIR, settings  # noqa: E402
from main import app  # noqa: E402
from scripts.generate_rental_dataset import generate_reservations  # noqa: E402

# Deuxième vérification, après import : c'est bien cette adresse que l'application utilise
assert settings.db_url == TEST_DB_URL, "L'application n'utilise pas la base de test"

FIXED_TODAY = date(2026, 11, 9)
DEMO_VERSION_ID = "fce602f6-88ac-41b4-97da-01dacf575c7d"
DEMO_METRICS = {"mae": 6.728, "r2": 0.9875, "intervalCoverage": 0.9075, "validationMae": 6.934}
REAL_V1_FILE = SERVICE_DIR / "artifacts" / "price_model_v1.joblib"   # seulement LU (copié)
SMALL_DATASET_ROWS = 1500


@pytest.fixture(scope="session", autouse=True)
def migrated_test_database():
    """Une fois pour toute la session : applique les vraies migrations Alembic sur ml_test_db."""
    alembic_config = Config(str(SERVICE_DIR / "alembic.ini"))
    command.upgrade(alembic_config, "head")


@pytest.fixture(autouse=True)
def reset_database():
    """Avant chaque test : tables vidées, v1 de démo active, prochain numéro de version = 2."""
    with engine.begin() as connection:
        connection.execute(text("TRUNCATE prediction_logs, model_versions, completed_reservations"))
        connection.execute(
            text("INSERT INTO model_versions (id, name, version, metrics, trained_at, active) "
                 "VALUES (:id, 'XGBoost', 1, CAST(:metrics AS jsonb), '2026-11-09T18:40:09Z', true)"),
            {"id": DEMO_VERSION_ID, "metrics": json.dumps(DEMO_METRICS)})
        connection.execute(text("SELECT setval('model_version_number_seq', 2, false)"))


@pytest.fixture(autouse=True)
def temporary_artifacts_dir(tmp_path, monkeypatch):
    """Dossier de modèles temporaire contenant une COPIE de la v1 ; le vrai artifacts/ n'est jamais modifié."""
    artifacts_dir = tmp_path / "artifacts"
    artifacts_dir.mkdir()
    shutil.copy2(REAL_V1_FILE, artifacts_dir / REAL_V1_FILE.name)
    monkeypatch.setattr(model_artifacts, "ARTIFACTS_DIR", artifacts_dir)
    price_predictor._loaded_bundles.clear()
    yield artifacts_dir
    price_predictor._loaded_bundles.clear()


@pytest.fixture(autouse=True)
def fixed_today(monkeypatch):
    """« Aujourd'hui » = 2026-11-09 pour toutes les règles de dates."""
    monkeypatch.setattr(price_predictor, "today_utc", lambda: FIXED_TODAY)


@pytest.fixture(scope="session")
def small_dataset_csv(tmp_path_factory):
    """Petit jeu synthétique (1 500 réservations) pour des entraînements rapides."""
    csv_path = tmp_path_factory.mktemp("data") / "small_reservations.csv"
    generate_reservations(SMALL_DATASET_ROWS, seed=42).to_csv(csv_path, index=False, float_format="%.3f")
    return csv_path


@pytest.fixture(autouse=True)
def fast_training(small_dataset_csv, monkeypatch):
    """POST /trainings entraîne sur le petit jeu (moins d'une seconde au lieu de ~2 s)."""
    original_training = model_training.train_new_model_version
    monkeypatch.setattr(model_training, "train_new_model_version",
                        lambda version, **options: original_training(version, csv_path=small_dataset_csv, **options))


# ---------- Tokens de test (Keycloak simulé) ----------
# On signe nos propres tokens avec une clé RSA de test. Seule la recherche de la clé publique
# est remplacée : la vraie vérification (signature, exp, iss, rôles) est donc testée.
TEST_SIGNING_KEY = rsa.generate_private_key(public_exponent=65537, key_size=2048)
OTHER_SIGNING_KEY = rsa.generate_private_key(public_exponent=65537, key_size=2048)   # « faussaire »


def create_token(roles: list[str], expires_in_seconds: int = 300, signing_key=TEST_SIGNING_KEY,
                 issuer: str | None = None) -> str:
    """Token JWT signé RS256, avec les mêmes claims utiles qu'un token Keycloak."""
    now = int(time.time())
    claims = {"iss": issuer or settings.keycloak_issuer, "sub": "test-user", "iat": now,
              "exp": now + expires_in_seconds, "roles": roles, "preferred_username": "test"}
    return jwt.encode(claims, signing_key, algorithm="RS256")


def bearer(token: str) -> dict:
    return {"Authorization": f"Bearer {token}"}


@pytest.fixture(autouse=True)
def test_keycloak_public_key(monkeypatch):
    """La « clé publique de Keycloak » est celle de notre paire de test."""
    monkeypatch.setattr(keycloak_security, "get_signing_key", lambda token: TEST_SIGNING_KEY.public_key())


@pytest.fixture
def client():
    """Client HTTP de test avec un token ADMIN : envoie de vraies requêtes, sans lancer uvicorn."""
    with TestClient(app, headers=bearer(create_token(["ADMIN"]))) as test_client:
        yield test_client


@pytest.fixture
def anonymous_client():
    """Client HTTP de test SANS token."""
    with TestClient(app) as test_client:
        yield test_client


@pytest.fixture
def internal_headers() -> dict:
    """En-tête d'un compte de service (rôle INTERNAL), pour les routes /internal."""
    return bearer(create_token(["INTERNAL"]))


@pytest.fixture
def run_sql():
    """Exécute une requête SQL sur la base de test et renvoie la première valeur (ex. un COUNT)."""
    def execute(sql: str):
        with engine.begin() as connection:
            result = connection.execute(text(sql))
            return result.scalar() if result.returns_rows else None
    return execute
