"""Configuration du service : variables d'environnement, fichier .env, Config Server, valeurs par défaut.

Priorité (de la plus forte à la plus faible) :
    variables d'environnement  >  .env  >  Config Server (si CONFIG_SERVER_URL)  >  valeurs par défaut ci-dessous
Les secrets (KEYCLOAK_CLIENT_SECRET, DB_URL...) ne viennent jamais du Config Server.

Ailleurs dans le code, on écrit simplement : from app.settings import settings
"""
from pathlib import Path
from typing import Any

from pydantic.fields import FieldInfo
from pydantic_settings import BaseSettings, PydanticBaseSettingsSource, SettingsConfigDict

from app.config_server import config_url, fetch_config_server_values

# Dossier racine du service (services/ml-service), quel que soit le dossier de lancement
SERVICE_DIR = Path(__file__).resolve().parent.parent


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=SERVICE_DIR / ".env", extra="ignore")

    service_port: int = 8107
    service_host: str = "host.docker.internal"
    db_url: str = "postgresql+psycopg://ml_svc:ml_pwd@localhost:5432/ml_db"

    # Config Server (facultatif) : vide = pas d'appel
    config_server_url: str = ""

    # Intégrations
    eureka_url: str = "http://localhost:8761/eureka"
    eureka_enabled: bool = True          # false dans les tests : pas d'inscription dans Eureka
    eureka_app_name: str = "RENTAL-ML"
    # Émetteur attendu dans les tokens (claim iss) ; les clés publiques sont lues dans son JWKS
    keycloak_issuer: str = "http://localhost:8180/realms/location-voitures"
    keycloak_jwks_url: str = ""          # vide = <KEYCLOAK_ISSUER>/protocol/openid-connect/certs
    keycloak_client_id: str = "ml-service"
    keycloak_client_secret: str = ""
    rabbitmq_url: str = "amqp://guest:guest@localhost:5672/"
    rabbitmq_enabled: bool = True        # false dans les tests : pas de consommateur RabbitMQ
    rabbitmq_queue: str = "ml.reservation_completed"

    @property
    def jwks_url(self) -> str:
        """Adresse des clés publiques de Keycloak (JWKS)."""
        return self.keycloak_jwks_url or f"{self.keycloak_issuer}/protocol/openid-connect/certs"


class ConfigServerSource(PydanticBaseSettingsSource):
    """Source de valeurs « Config Server », placée APRÈS l'environnement et le .env (donc moins prioritaire)."""

    def __init__(self, settings_cls: type[BaseSettings], values: dict):
        super().__init__(settings_cls)
        self.values = values

    def get_field_value(self, field: FieldInfo, field_name: str) -> tuple[Any, str, bool]:
        return self.values.get(field_name), field_name, False

    def __call__(self) -> dict[str, Any]:
        return {name: value for name, value in self.values.items() if name in self.settings_cls.model_fields}


def load_settings(**overrides: Any) -> tuple[Settings, str]:
    """Construit la configuration et dit d'où elle vient (affiché au démarrage).
    overrides : pour les tests (ex. _env_file=...)."""
    local_settings = Settings(**overrides)
    if not local_settings.config_server_url:
        return local_settings, "variables d'environnement et .env (pas de CONFIG_SERVER_URL)"

    remote_values = fetch_config_server_values(local_settings.config_server_url)
    if not remote_values:
        return local_settings, f"variables d'environnement et .env (Config Server injoignable : {local_settings.config_server_url})"

    class SettingsWithConfigServer(Settings):
        @classmethod
        def settings_customise_sources(cls, settings_cls, init_settings, env_settings, dotenv_settings,
                                       file_secret_settings):
            # Ordre = priorité : init > environnement > .env > Config Server > (défauts du code)
            return (init_settings, env_settings, dotenv_settings,
                    ConfigServerSource(settings_cls, remote_values), file_secret_settings)

    return (SettingsWithConfigServer(**overrides),
            f"Config Server {config_url(local_settings.config_server_url)} ({len(remote_values)} valeurs) + .env pour les secrets")


settings, settings_origin = load_settings()
