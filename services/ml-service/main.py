"""Point d'entrée du service. Lancement : uvicorn main:app --port 8107"""
from fastapi import FastAPI

app = FastAPI(
    title="ml-service",
    description="Tarification dynamique des locations de voitures (prix journalier conseillé).",
    version="0.1.0",
)


@app.get("/health", tags=["health"], summary="État du service")
def health() -> dict:
    return {"status": "UP"}
