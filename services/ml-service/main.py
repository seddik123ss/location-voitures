"""Point d'entrée du service. Lancement : uvicorn main:app --port 8107"""
import uuid

from fastapi import FastAPI, Request

from app import internal_routes, public_routes
from app.error_handlers import register_error_handlers

app = FastAPI(
    title="ml-service",
    description="Tarification dynamique des locations de voitures (prix journalier conseillé).",
    version="0.1.0",
)

register_error_handlers(app)
app.include_router(public_routes.router)
app.include_router(internal_routes.router)


@app.middleware("http")
async def propagate_correlation_id(request: Request, call_next):
    """Convention de l'équipe : renvoyer X-Correlation-Id tel quel (ou en créer un s'il manque)."""
    correlation_id = request.headers.get("X-Correlation-Id") or str(uuid.uuid4())
    response = await call_next(request)
    response.headers["X-Correlation-Id"] = correlation_id
    return response


@app.get("/health", tags=["Santé"], summary="État du service")
def health() -> dict:
    return {"status": "UP"}
