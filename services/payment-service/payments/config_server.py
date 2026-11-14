"""
Configuration NON secrète lue au démarrage sur le Config Server (Spring Cloud Config, infra/config-server).

GET {CONFIG_SERVER_URL}/rental-payment/default renvoie infra/config-repo/rental-payment.yml et application.yml
sous forme de "propertySources" (le plus prioritaire en premier).

Priorité : variable d'environnement ou .env  >  Config Server  >  valeur par défaut de settings.py.
Seules les variables encore absentes sont remplies : un .env (ou docker-compose) peut toujours forcer une valeur.
Les secrets (base, Keycloak, Django) ne viennent jamais du Config Server.
"""
import json
import logging
import os
import urllib.request

logger = logging.getLogger("payments.config_server")

CONFIG_APPLICATION = "rental-payment"
CONFIG_PROFILE = "default"
TIMEOUT_SECONDS = 3

# Clé du Config Server -> variable d'environnement lue par settings.py
CONFIG_KEYS = {
    "service.port": "SERVICE_PORT",
    "service.host": "SERVICE_HOST",
    "eureka.url": "EUREKA_URL",
    "keycloak.issuer": "KEYCLOAK_ISSUER",
}


def config_url(base_url: str) -> str:
    return f"{base_url.rstrip('/')}/{CONFIG_APPLICATION}/{CONFIG_PROFILE}"


def fetch_values(base_url: str) -> dict:
    """{VARIABLE: valeur} lus sur le Config Server ; dictionnaire vide s'il est injoignable ou illisible."""
    url = config_url(base_url)
    try:
        with urllib.request.urlopen(url, timeout=TIMEOUT_SECONDS) as response:
            document = json.load(response)
        merged: dict = {}
        # propertySources : rental-payment.yml (spécifique) avant application.yml (commun) :
        # on applique du moins prioritaire au plus prioritaire.
        for source in reversed(document["propertySources"]):
            merged.update(source["source"])
    except Exception as error:  # Config Server arrêté, délai dépassé, JSON inattendu
        logger.warning("Config Server injoignable (%s : %s) : démarrage avec le .env et les valeurs par défaut", url, error)
        return {}
    return {variable: str(merged[key]) for key, variable in CONFIG_KEYS.items() if key in merged}


def apply_to_environment(base_url: str, environ=os.environ) -> dict:
    """Remplit les variables encore absentes avec les valeurs du Config Server. Renvoie celles appliquées."""
    if not base_url:
        return {}
    applied = {}
    for variable, value in fetch_values(base_url).items():
        if not environ.get(variable):
            environ[variable] = value
            applied[variable] = value
    if applied:
        logger.info("Configuration lue sur le Config Server %s : %s", config_url(base_url), ", ".join(sorted(applied)))
    return applied
