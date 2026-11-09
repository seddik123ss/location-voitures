"""données de démo : version 1 du modèle (XGBoost), active

Le fichier correspondant est artifacts/price_model_v1.joblib (commité).
Les valeurs viennent du bundle produit par notebooks/price_model_training.ipynb
(métriques mesurées sur le jeu de test).

Revision ID: 0002_demo_v1
Revises: 62aa18c95bfe
"""
import uuid
from datetime import datetime, timezone
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql

revision: str = "0002_demo_v1"
down_revision: Union[str, Sequence[str], None] = "62aa18c95bfe"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# UUID fixe : le même sur toutes les machines de l'équipe
DEMO_MODEL_VERSION_ID = "fce602f6-88ac-41b4-97da-01dacf575c7d"


def upgrade() -> None:
    model_versions = sa.table(
        "model_versions",
        sa.column("id", sa.Uuid()),
        sa.column("name", sa.String()),
        sa.column("version", sa.Integer()),
        sa.column("metrics", postgresql.JSONB()),
        sa.column("trained_at", sa.DateTime(timezone=True)),
        sa.column("active", sa.Boolean()),
    )
    op.bulk_insert(model_versions, [{
        "id": uuid.UUID(DEMO_MODEL_VERSION_ID),
        "name": "XGBoost",
        "version": 1,
        "metrics": {"mae": 6.728, "r2": 0.9875, "intervalCoverage": 0.9075, "validationMae": 6.934},
        "trained_at": datetime(2026, 11, 9, 18, 40, 9, tzinfo=timezone.utc),
        "active": True,
    }])


def downgrade() -> None:
    op.execute(sa.text("DELETE FROM model_versions WHERE id = :id").bindparams(id=DEMO_MODEL_VERSION_ID))
