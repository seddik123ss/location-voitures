"""Connexion à PostgreSQL avec SQLAlchemy.

- engine          : la connexion à la base (adresse lue dans DB_URL)
- SessionFactory  : fabrique de sessions (une session = une conversation avec la base)
- Base            : classe mère de toutes les tables (db_tables.py)
- get_db_session  : fournit une session à chaque requête HTTP, puis la ferme
"""
from collections.abc import Iterator

from sqlalchemy import create_engine
from sqlalchemy.orm import DeclarativeBase, Session, sessionmaker

from app.settings import settings

# pool_pre_ping : vérifie qu'une connexion est encore vivante avant de la réutiliser
engine = create_engine(settings.db_url, pool_pre_ping=True)
SessionFactory = sessionmaker(bind=engine, autoflush=False, expire_on_commit=False)


class Base(DeclarativeBase):
    """Classe mère des tables : Alembic lit Base.metadata pour générer les migrations."""


def get_db_session() -> Iterator[Session]:
    """Dépendance FastAPI : ouvre une session pour la requête et la ferme à la fin, même en cas d'erreur."""
    with SessionFactory() as session:
        yield session
