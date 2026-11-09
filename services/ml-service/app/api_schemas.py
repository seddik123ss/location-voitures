"""Formes JSON d'entrée et de sortie de l'API (Pydantic).

En Python on écrit en snake_case (start_date), le JSON est en camelCase (startDate) :
la conversion est automatique grâce à alias_generator=to_camel.
Les descriptions et exemples de chaque champ apparaissent dans Swagger.
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

    category_id: uuid.UUID = Field(description="Catégorie de véhicule (UUID du vehicle-service)",
                                   examples=["a6ec349d-0ff0-4cb1-bfb9-453dd8e2ee8c"])
    agency_id: uuid.UUID = Field(description="Agence de départ (UUID du vehicle-service)",
                                 examples=["1deb1dea-ab54-436d-8ed7-c3322f782c88"])
    start_date: date = Field(description="Date de départ, aujourd'hui au plus tôt, au plus dans 180 jours",
                             examples=["2026-12-18"])
    end_date: date = Field(description="Date de retour, après le départ, durée de 30 jours au plus",
                           examples=["2026-12-22"])
    occupancy_rate: float | None = Field(
        default=None, examples=[0.85],
        description="Optionnel, entre 0 et 1. Sinon estimé : moyenne historique agence × saison")


class PricePredictionResponse(CamelModel):
    daily_price: float = Field(description="Prix journalier conseillé (TND, 3 décimales)", examples=[154.471])
    lower_bound: float = Field(description="Bas de l'intervalle à 90 % (TND)", examples=[140.6])
    upper_bound: float = Field(description="Haut de l'intervalle à 90 % (TND)", examples=[168.08])
    currency: str = Field(default="TND", description="Devise des montants", examples=["TND"])
    model_version: int = Field(description="Numéro de la version du modèle utilisée", examples=[1])


# ---------- Versions du modèle ----------

class ModelVersionResponse(CamelModel):
    model_config = ConfigDict(from_attributes=True)   # se construit directement depuis une ligne ModelVersion

    id: uuid.UUID = Field(examples=["fce602f6-88ac-41b4-97da-01dacf575c7d"])
    name: str = Field(description="Nom lisible (modifiable)", examples=["XGBoost"])
    version: int = Field(description="Numéro de version, jamais réutilisé", examples=[1])
    metrics: dict[str, float] = Field(
        description="Mesurées sur le jeu de test : mae (TND), r2, intervalCoverage ; et validationMae",
        examples=[{"mae": 6.728, "r2": 0.9875, "intervalCoverage": 0.9075, "validationMae": 6.934}])
    trained_at: datetime = Field(description="Date d'entraînement (UTC)", examples=["2026-11-09T18:40:09Z"])
    active: bool = Field(description="Vrai pour la seule version qui calcule les prix", examples=[True])

    @field_serializer("trained_at")
    def format_instant_utc(self, instant: datetime) -> str:
        """Convention de l'équipe : instant ISO 8601 en UTC, ex. 2026-11-09T18:40:09Z"""
        return instant.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


class ModelVersionUpdateRequest(CamelModel):
    """Seul le nom est modifiable ; tout autre champ (active, metrics...) est refusé (422)."""
    model_config = ConfigDict(extra="forbid")

    name: str = Field(min_length=1, max_length=100, description="Nouveau nom", examples=["XGBoost (démo)"])


class ModelVersionPage(CamelModel):
    items: list[ModelVersionResponse]
    page: int = Field(examples=[0])
    size: int = Field(examples=[20])
    total: int = Field(examples=[1])


# ---------- Format d'erreur commun ----------

class ErrorDetail(BaseModel):
    field: str = Field(examples=["startDate"])
    message: str = Field(examples=["la date de départ est dans le passé"])


class ErrorResponse(BaseModel):
    """Format d'erreur commun de l'équipe (docs/conventions.md)."""
    status: int = Field(examples=[422])
    error: str = Field(examples=["UNPROCESSABLE_ENTITY"])
    message: str = Field(examples=["Demande de prédiction invalide"])
    details: list[ErrorDetail] = []
