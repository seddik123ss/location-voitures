"""Formes JSON d'entrée et de sortie de l'API (Pydantic).

En Python on écrit en snake_case (start_date), le JSON est en camelCase (startDate) :
la conversion est automatique grâce à alias_generator=to_camel.
"""
import uuid
from datetime import date, datetime, timezone

from pydantic import BaseModel, ConfigDict, Field, field_serializer
from pydantic.alias_generators import to_camel


class CamelModel(BaseModel):
    """Base commune : JSON en camelCase, et les noms Python acceptés aussi."""
    model_config = ConfigDict(alias_generator=to_camel, populate_by_name=True, protected_namespaces=())


# ---------- Prédiction de prix ----------

class PricePredictionRequest(CamelModel):
    """Entrée de POST /api/ml/price-predictions et /internal/ml/price-predictions."""
    model_config = ConfigDict(extra="forbid")

    category_id: uuid.UUID
    agency_id: uuid.UUID
    start_date: date
    end_date: date
    # Optionnel : sinon estimé (moyenne historique de l'agence pour la saison du départ)
    occupancy_rate: float | None = None


class PricePredictionResponse(CamelModel):
    daily_price: float          # prix journalier conseillé (TND, 3 décimales)
    lower_bound: float          # bas de l'intervalle (TND)
    upper_bound: float          # haut de l'intervalle (TND)
    currency: str = "TND"
    model_version: int          # numéro de la version du modèle utilisée


# ---------- Versions du modèle ----------

class ModelVersionResponse(CamelModel):
    model_config = ConfigDict(from_attributes=True)   # se construit directement depuis une ligne ModelVersion

    id: uuid.UUID
    name: str
    version: int
    metrics: dict[str, float]
    trained_at: datetime
    active: bool

    @field_serializer("trained_at")
    def format_instant_utc(self, instant: datetime) -> str:
        """Convention de l'équipe : instant ISO 8601 en UTC, ex. 2026-11-09T18:40:09Z"""
        return instant.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


class ModelVersionUpdateRequest(CamelModel):
    """Seul le nom est modifiable ; tout autre champ (active, metrics...) est refusé (422)."""
    model_config = ConfigDict(extra="forbid")

    name: str = Field(min_length=1, max_length=100)


class ModelVersionPage(CamelModel):
    items: list[ModelVersionResponse]
    page: int
    size: int
    total: int


# ---------- Format d'erreur commun ----------

class ErrorDetail(BaseModel):
    field: str
    message: str


class ErrorResponse(BaseModel):
    status: int
    error: str
    message: str
    details: list[ErrorDetail] = []
