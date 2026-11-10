"""Point d'entrée du service. Lancement : uvicorn main:app --host 0.0.0.0 --port 8107
(0.0.0.0 obligatoire pour que la Gateway, dans Docker, puisse joindre le service : docs/conventions.md)"""
import logging
import uuid
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request

from app import api_docs, internal_routes, public_routes
from app.eureka_client import register_in_eureka, unregister_from_eureka
from app.error_handlers import register_error_handlers
from app.rabbitmq_consumer import start_consumer, stop_consumer
from app.settings import settings_origin

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s : %(message)s")


@asynccontextmanager
async def lifespan(application: FastAPI):
    """Avant « yield » : au démarrage du serveur. Après : à son arrêt."""
    logging.getLogger("ml-service").info("Configuration : %s", settings_origin)
    await register_in_eureka()
    await start_consumer()          # file RabbitMQ ml.reservation_completed
    yield
    await stop_consumer()
    await unregister_from_eureka()


app = FastAPI(
    lifespan=lifespan,
    title="ml-service",
    version="0.1.0",
    description=(
        "Tarification dynamique des locations de voitures : **prix journalier conseillé** "
        "(TND, 3 décimales) et intervalle à 90 %, calculés par un modèle XGBoost versionné.\n\n"
        "Erreurs au format commun `{status, error, message, details}`. "
        "Contrat de l'équipe : `docs/openapi/ml-service.yaml`."
    ),
    openapi_tags=api_docs.OPENAPI_TAGS,
)

register_error_handlers(app)
app.include_router(public_routes.router)
app.include_router(internal_routes.router)


def build_openapi_schema() -> dict:
    """Document OpenAPI de FastAPI, sans les réponses 422 qu'il ajoute d'office (voir api_docs)."""
    return api_docs.remove_default_validation_errors(FastAPI.openapi(app))


app.openapi = build_openapi_schema


@app.middleware("http")
async def propagate_correlation_id(request: Request, call_next):
    """Convention de l'équipe : renvoyer X-Correlation-Id tel quel (ou en créer un s'il manque)."""
    correlation_id = request.headers.get("X-Correlation-Id") or str(uuid.uuid4())
    response = await call_next(request)
    response.headers["X-Correlation-Id"] = correlation_id
    return response


@app.get("/health", tags=["Santé"], summary="État du service", responses=api_docs.HEALTH_RESPONSE)
def health() -> dict:
    return {"status": "UP"}
