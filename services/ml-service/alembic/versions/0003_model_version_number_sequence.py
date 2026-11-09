"""séquence des numéros de version : un numéro n'est jamais réutilisé

Avant : numéro = MAX(version) + 1, donc supprimer la v2 puis réentraîner redonnait « v2 ».
Maintenant : numéro = nextval('model_version_number_seq'). Une séquence PostgreSQL ne revient
jamais en arrière, même après une suppression ou un entraînement qui échoue.

Revision ID: 0003_version_sequence
Revises: 0002_demo_v1
"""
from typing import Sequence, Union

from alembic import op

revision: str = "0003_version_sequence"
down_revision: Union[str, Sequence[str], None] = "0002_demo_v1"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.execute("CREATE SEQUENCE model_version_number_seq START WITH 1 OWNED BY model_versions.version")
    # La séquence démarre après la plus haute version déjà en base (la v1 de démo -> prochain = 2)
    op.execute(
        "SELECT setval('model_version_number_seq', "
        "COALESCE((SELECT MAX(version) FROM model_versions), 0) + 1, false)")


def downgrade() -> None:
    op.execute("DROP SEQUENCE model_version_number_seq")
