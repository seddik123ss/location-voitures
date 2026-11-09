"""Tables de la base ml_db (SQLAlchemy). Nommé db_tables pour ne pas confondre avec le modèle ML."""
import uuid
from datetime import datetime, timezone

from sqlalchemy import Boolean, DateTime, ForeignKey, Index, Integer, Sequence, String, Uuid, text
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.orm import Mapped, mapped_column

from app.database import Base


def utc_now() -> datetime:
    return datetime.now(timezone.utc)


# Compteur PostgreSQL des numéros de version : nextval() ne redonne JAMAIS deux fois le même nombre,
# même si une version est supprimée ou si un entraînement échoue (le numéro est alors perdu, pas réutilisé).
MODEL_VERSION_NUMBER_SEQUENCE = Sequence("model_version_number_seq", metadata=Base.metadata)


class ModelVersion(Base):
    """Une version entraînée du modèle de prix (fichier artifacts/price_model_v<version>.joblib)."""
    __tablename__ = "model_versions"

    id: Mapped[uuid.UUID] = mapped_column(Uuid, primary_key=True, default=uuid.uuid4)
    name: Mapped[str] = mapped_column(String(100))
    version: Mapped[int] = mapped_column(Integer, unique=True)
    metrics: Mapped[dict] = mapped_column(JSONB)          # {"mae", "r2", "intervalCoverage", "validationMae"}
    trained_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    active: Mapped[bool] = mapped_column(Boolean, default=False, server_default=text("false"))

    __table_args__ = (
        # Une seule version active : index unique PARTIEL, limité aux lignes où active = true.
        # PostgreSQL refuse donc une deuxième ligne active, même en cas de bug dans le code.
        Index("uq_model_versions_single_active", "active", unique=True, postgresql_where=text("active")),
    )


class PredictionLog(Base):
    """Trace de chaque prédiction : ce qui a été demandé, ce qui a été répondu, avec quelle version."""
    __tablename__ = "prediction_logs"

    id: Mapped[uuid.UUID] = mapped_column(Uuid, primary_key=True, default=uuid.uuid4)
    input: Mapped[dict] = mapped_column(JSONB)            # requête reçue + variables calculées
    output: Mapped[dict] = mapped_column(JSONB)           # réponse renvoyée
    # Si la version est supprimée, le log reste (output garde le numéro de version)
    model_version_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("model_versions.id", ondelete="SET NULL"), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utc_now)
