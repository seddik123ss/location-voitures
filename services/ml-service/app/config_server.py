"""Lecture de la configuration NON secrète depuis le Config Server (Spring Cloud Config, infra/config-server).

GET {CONFIG_SERVER_URL}/rental-ml/default renvoie les fichiers application.yml et rental-ml.yml
de infra/config-repo, sous forme de « propertySources » (le plus prioritaire en premier).
Les secrets n'y sont jamais : ils restent dans le .env du service.
"""
import json
import logging
import urllib.request

CONFIG_APPLICATION = "rental-ml"
CONFIG_PROFILE = "default"

# Clé dans le Config Server -> champ de Settings (seules ces clés sont utilisées)
CONFIG_KEYS = {
    "service.port": "service_port",
    "service.host": "service_host",
    "service.eureka-name": "eureka_app_name",
    "eureka.url": "eureka_url",
    "keycloak.issuer": "keycloak_issuer",
    "ml.rabbitmq.queue": "rabbitmq_queue",
}

logger = logging.getLogger("ml-service")


def config_url(config_server_url: str) -> str:
    return f"{config_server_url.rstrip('/')}/{CONFIG_APPLICATION}/{CONFIG_PROFILE}"


def fetch_config_server_values(config_server_url: str, timeout_seconds: float = 3) -> dict:
    """Valeurs du Config Server, déjà converties en champs de Settings.
    Config Server injoignable ou réponse illisible : avertissement, et dictionnaire vide."""
    url = config_url(config_server_url)
    try:
        with urllib.request.urlopen(url, timeout=timeout_seconds) as response:
            document = json.load(response)
        merged: dict = {}
        # propertySources : rental-ml.yml (spécifique) avant application.yml (commun).
        # On applique du moins prioritaire au plus prioritaire.
        for property_source in reversed(document["propertySources"]):
            merged.update(property_source["source"])
    except Exception as error:
        logger.warning("Config Server injoignable (%s : %s) : démarrage avec le .env et les valeurs par défaut",
                       url, error)
        return {}
    return {field: merged[key] for key, field in CONFIG_KEYS.items() if key in merged}
