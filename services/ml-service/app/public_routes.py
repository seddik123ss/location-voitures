"""Routes publiques /api/ml (passent par la Gateway).

Les fonctions restent courtes : elles reçoivent la requête et délèguent
à price_predictor (prédiction), model_registry (versions) et model_training (entraînement).
Plus tard, la sécurité Keycloak s'ajoutera ici : APIRouter(..., dependencies=[...]).
"""
import uuid
from typing import Annotated

from fastapi import APIRouter, Depends, Path, Query, Response
from sqlalchemy.orm import Session

from app import model_registry, model_training, price_predictor
from app.api_schemas import (
    ModelVersionPage, ModelVersionResponse, ModelVersionUpdateRequest,
    PricePredictionRequest, PricePredictionResponse,
)
from app.database import get_db_session

router = APIRouter(prefix="/api/ml")

# Identifiant de version dans l'URL : {id} côté API, model_id côté Python
# (on évite d'appeler une variable « id », qui masquerait la fonction id() de Python)
ModelId = Annotated[uuid.UUID, Path(alias="id")]


@router.post("/price-predictions", response_model=PricePredictionResponse, tags=["Prédiction de prix"])
def create_price_prediction(prediction_request: PricePredictionRequest,
                            session: Session = Depends(get_db_session)):
    return price_predictor.predict_price(session, prediction_request)


@router.get("/models", response_model=ModelVersionPage, tags=["Versions du modèle"])
def list_models(page: int = Query(0, ge=0), size: int = Query(20, ge=1, le=100),
                session: Session = Depends(get_db_session)):
    page_items, total = model_registry.list_model_versions(session, page, size)
    return ModelVersionPage(
        items=[ModelVersionResponse.model_validate(item) for item in page_items],
        page=page, size=size, total=total)


@router.get("/models/{id}", response_model=ModelVersionResponse, tags=["Versions du modèle"])
def get_model(model_id: ModelId, session: Session = Depends(get_db_session)):
    return model_registry.get_model_version(session, model_id)


@router.put("/models/{id}", response_model=ModelVersionResponse, tags=["Versions du modèle"])
def update_model(model_id: ModelId, update_request: ModelVersionUpdateRequest,
                 session: Session = Depends(get_db_session)):
    return model_registry.rename_model_version(session, model_id, update_request.name)


@router.delete("/models/{id}", status_code=204, tags=["Versions du modèle"])
def delete_model(model_id: ModelId, session: Session = Depends(get_db_session)):
    model_registry.delete_model_version(session, model_id)
    return Response(status_code=204)


@router.post("/models/{id}/activate", response_model=ModelVersionResponse, tags=["Versions du modèle"])
def activate_model(model_id: ModelId, session: Session = Depends(get_db_session)):
    return model_registry.activate_model_version(session, model_id)


@router.post("/trainings", status_code=201, response_model=ModelVersionResponse, tags=["Entraînement"])
def create_training(response: Response, session: Session = Depends(get_db_session)):
    version = model_registry.next_version_number(session)
    model_bundle = model_training.train_new_model_version(version)
    model_version = model_registry.register_model_version(session, model_bundle)
    response.headers["Location"] = f"/api/ml/models/{model_version.id}"
    return model_version
