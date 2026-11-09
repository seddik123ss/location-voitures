"""Routes publiques /api/ml (passent par la Gateway).

Les fonctions restent courtes : elles reçoivent la requête et délèguent
à price_predictor (prédiction), model_registry (versions) et model_training (entraînement).
Les textes de Swagger sont dans api_docs.py.
Sécurité : toutes les routes /api/ml exigent un token Keycloak avec le rôle ADMIN (une seule ligne, sur le router).
"""
import uuid
from typing import Annotated

from fastapi import APIRouter, Body, Depends, Path, Query, Response
from sqlalchemy.orm import Session

from app import api_docs, completed_reservations, model_registry, model_training, price_predictor
from app.api_schemas import (
    ModelVersionPage, ModelVersionResponse, ModelVersionUpdateRequest,
    PricePredictionRequest, PricePredictionResponse,
)
from app.database import get_db_session
from app.keycloak_security import require_roles

router = APIRouter(prefix="/api/ml", dependencies=[Depends(require_roles("ADMIN"))],
                   responses=api_docs.ADMIN_SECURITY_ERRORS)

# Identifiant de version dans l'URL : {id} côté API, model_id côté Python
# (on évite d'appeler une variable « id », qui masquerait la fonction id() de Python)
ModelId = Annotated[uuid.UUID, Path(alias="id", description="UUID de la version du modèle")]


@router.post("/price-predictions", response_model=PricePredictionResponse, tags=["Prédiction de prix"],
             summary="Prix journalier conseillé", description=api_docs.PUBLIC_PREDICTION_DESCRIPTION,
             response_description="Prix conseillé et intervalle", responses=api_docs.PREDICTION_ERRORS)
def create_price_prediction(
        prediction_request: Annotated[PricePredictionRequest,
                                      Body(openapi_examples=api_docs.PREDICTION_REQUEST_EXAMPLES)],
        session: Session = Depends(get_db_session)):
    return price_predictor.predict_price(session, prediction_request)


@router.get("/models", response_model=ModelVersionPage, tags=["Versions du modèle"],
            summary="Lister les versions", description=api_docs.LIST_DESCRIPTION,
            responses={400: api_docs.INVALID_PAGINATION})
def list_models(page: int = Query(0, ge=0, description="Numéro de page (commence à 0)"),
                size: int = Query(20, ge=1, le=100, description="Taille de page (1 à 100)"),
                session: Session = Depends(get_db_session)):
    page_items, total = model_registry.list_model_versions(session, page, size)
    return ModelVersionPage(
        items=[ModelVersionResponse.model_validate(item) for item in page_items],
        page=page, size=size, total=total)


@router.get("/models/{id}", response_model=ModelVersionResponse, tags=["Versions du modèle"],
            summary="Détail d'une version", description=api_docs.GET_DESCRIPTION,
            responses={400: api_docs.INVALID_ID, 404: api_docs.MODEL_NOT_FOUND})
def get_model(model_id: ModelId, session: Session = Depends(get_db_session)):
    return model_registry.get_model_version(session, model_id)


@router.put("/models/{id}", response_model=ModelVersionResponse, tags=["Versions du modèle"],
            summary="Renommer une version", description=api_docs.UPDATE_DESCRIPTION,
            responses={400: api_docs.INVALID_ID, 404: api_docs.MODEL_NOT_FOUND, 422: api_docs.UPDATE_INVALID})
def update_model(model_id: ModelId, update_request: ModelVersionUpdateRequest,
                 session: Session = Depends(get_db_session)):
    return model_registry.rename_model_version(session, model_id, update_request.name)


@router.delete("/models/{id}", status_code=204, tags=["Versions du modèle"],
               summary="Supprimer une version inactive", description=api_docs.DELETE_DESCRIPTION,
               response_description="Version supprimée",
               responses={400: api_docs.INVALID_ID, 404: api_docs.MODEL_NOT_FOUND,
                          409: api_docs.DELETE_ACTIVE_CONFLICT})
def delete_model(model_id: ModelId, session: Session = Depends(get_db_session)):
    model_registry.delete_model_version(session, model_id)
    return Response(status_code=204)


@router.post("/models/{id}/activate", response_model=ModelVersionResponse, tags=["Versions du modèle"],
             summary="Activer une version", description=api_docs.ACTIVATE_DESCRIPTION,
             response_description="La version, maintenant active",
             responses={400: api_docs.INVALID_ID, 404: api_docs.MODEL_NOT_FOUND,
                        409: api_docs.ACTIVATE_FILE_CONFLICT})
def activate_model(model_id: ModelId, session: Session = Depends(get_db_session)):
    return model_registry.activate_model_version(session, model_id)


@router.post("/trainings", status_code=201, response_model=ModelVersionResponse, tags=["Entraînement"],
             summary="Réentraîner le modèle (nouvelle version)", description=api_docs.TRAINING_DESCRIPTION,
             responses={201: api_docs.TRAINING_CREATED})
def create_training(response: Response, session: Session = Depends(get_db_session)):
    version = model_registry.next_version_number(session)
    # Historique synthétique + réservations réelles terminées (reçues par RabbitMQ)
    real_reservations = completed_reservations.load_training_rows(session)
    model_bundle = model_training.train_new_model_version(version, extra_reservations=real_reservations)
    model_version = model_registry.register_model_version(session, model_bundle)
    response.headers["Location"] = f"/api/ml/models/{model_version.id}"
    return model_version
