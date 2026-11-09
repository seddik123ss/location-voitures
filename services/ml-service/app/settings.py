"""Configuration du service, lue depuis les variables d'environnement ou le fichier .env.

Ailleurs dans le code, on écrit simplement : from app.settings import settings
"""
from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict

# Dossier racine du service (services/ml-service), quel que soit le dossier de lancement
SERVICE_DIR = Path(__file__).resolve().parent.parent


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=SERVICE_DIR / ".env", extra="ignore")

    service_port: int = 8107
    service_host: str = "host.docker.internal"
    db_url: str = "postgresql+psycopg://ml_svc:ml_pwd@localhost:5432/ml_db"

    # Intégrations
    eureka_url: str = "http://localhost:8761/eureka"
    eureka_enabled: bool = True          # false dans les tests : pas d'inscription dans Eureka
    # Émetteur attendu dans les tokens (claim iss) ; les clés publiques sont lues dans son JWKS
    keycloak_issuer: str = "http://localhost:8180/realms/location-voitures"
    keycloak_jwks_url: str = ""          # vide = <KEYCLOAK_ISSUER>/protocol/openid-connect/certs
    keycloak_client_id: str = "ml-service"
    keycloak_client_secret: str = ""
    rabbitmq_url: str = "amqp://guest:guest@localhost:5672/"

    @property
    def jwks_url(self) -> str:
        """Adresse des clés publiques de Keycloak (JWKS)."""
        return self.keycloak_jwks_url or f"{self.keycloak_issuer}/protocol/openid-connect/certs"


settings = Settings()
