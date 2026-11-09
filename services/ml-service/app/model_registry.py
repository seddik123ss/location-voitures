"""Gestion des versions du modèle : lister, lire, renommer, supprimer, activer, enregistrer."""
import uuid
from datetime import datetime

from sqlalchemy import func, select, update
from sqlalchemy.orm import Session

from app import model_artifacts, price_predictor
from app.db_tables import MODEL_VERSION_NUMBER_SEQUENCE, ModelVersion
from app.error_handlers import ConflictError, NotFoundError


def get_model_version(session: Session, model_id: uuid.UUID) -> ModelVersion:
    model_version = session.get(ModelVersion, model_id)
    if model_version is None:
        raise NotFoundError(f"Version de modèle {model_id} introuvable")
    return model_version


def list_model_versions(session: Session, page: int, size: int) -> tuple[list[ModelVersion], int]:
    """Une page de versions (la plus récente d'abord) et le nombre total."""
    total = session.scalar(select(func.count()).select_from(ModelVersion))
    page_items = session.scalars(
        select(ModelVersion).order_by(ModelVersion.version.desc()).offset(page * size).limit(size)).all()
    return list(page_items), total


def rename_model_version(session: Session, model_id: uuid.UUID, new_name: str) -> ModelVersion:
    model_version = get_model_version(session, model_id)
    model_version.name = new_name
    session.commit()
    return model_version


def delete_model_version(session: Session, model_id: uuid.UUID) -> None:
    """Supprime une version inactive (ligne + fichier .joblib). Les logs sont gardés (lien mis à NULL)."""
    model_version = get_model_version(session, model_id)
    if model_version.active:
        raise ConflictError("Impossible de supprimer la version active : activez d'abord une autre version")

    version_number = model_version.version
    session.delete(model_version)
    session.commit()
    model_artifacts.model_file_path(version_number).unlink(missing_ok=True)
    price_predictor.forget_model_bundle(version_number)


def activate_model_version(session: Session, model_id: uuid.UUID) -> ModelVersion:
    """Rend cette version active et désactive l'ancienne, dans UNE seule transaction."""
    model_version = get_model_version(session, model_id)
    if not model_artifacts.model_file_path(model_version.version).exists():
        raise ConflictError(f"Fichier du modèle v{model_version.version} introuvable : activation impossible")

    # 1) on désactive l'ancienne d'abord (sinon l'index unique refuserait 2 versions actives)
    session.execute(update(ModelVersion).where(ModelVersion.active).values(active=False))
    session.flush()
    # 2) puis on active la nouvelle ; commit = les deux changements ensemble, ou aucun
    model_version.active = True
    session.commit()
    return model_version


def next_version_number(session: Session) -> int:
    """Réserve un nouveau numéro de version avec la séquence PostgreSQL (jamais réutilisé).
    Avec MAX(version) + 1, supprimer la v2 puis réentraîner aurait redonné « v2 »."""
    return session.scalar(select(MODEL_VERSION_NUMBER_SEQUENCE.next_value()))


def register_model_version(session: Session, model_bundle: dict) -> ModelVersion:
    """Enregistre en base une version qui vient d'être entraînée (inactive par défaut)."""
    bundle_metrics = model_bundle["metrics"]
    model_version = ModelVersion(
        name=model_bundle["model_name"],
        version=model_bundle["version"],
        metrics={
            "mae": bundle_metrics["mae"],
            "r2": bundle_metrics["r2"],
            "intervalCoverage": bundle_metrics["interval_coverage"],
            "validationMae": bundle_metrics["validation_mae"],
        },
        trained_at=datetime.fromisoformat(model_bundle["trained_at"]),   # "2026-11-09T18:40:09Z" -> datetime
        active=False,
    )
    session.add(model_version)
    session.commit()
    return model_version
