"""Routes internes /internal/ml : appelées directement par les autres services (jamais par la Gateway).

Contrat (docs/internal-api.md) : entrée {categoryId, agencyId, startDate, endDate},
sortie {dailyPrice, lowerBound, upperBound, modelVersion} (+ currency).
Plus tard : token de compte de service avec le rôle INTERNAL (keycloak_security.py).
"""
from typing import Annotated

from fastapi import APIRouter, Body, Depends
from sqlalchemy.orm import Session

from app import api_docs, price_predictor
from app.api_schemas import PricePredictionRequest, PricePredictionResponse
from app.database import get_db_session

router = APIRouter(prefix="/internal/ml", tags=["Interne (entre services)"])


@router.post("/price-predictions", response_model=PricePredictionResponse,
             summary="Prix journalier conseillé (appel entre services)",
             description=api_docs.INTERNAL_PREDICTION_DESCRIPTION,
             response_description="Prix conseillé et intervalle", responses=api_docs.PREDICTION_ERRORS)
def create_internal_price_prediction(
        prediction_request: Annotated[PricePredictionRequest,
                                      Body(openapi_examples=api_docs.PREDICTION_REQUEST_EXAMPLES)],
        session: Session = Depends(get_db_session)):
    # Même logique que la route publique : une seule fonction, deux portes d'entrée
    return price_predictor.predict_price(session, prediction_request)
