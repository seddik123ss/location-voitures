"""Inscription du service dans Eureka sous le nom RENTAL-ML (py-eureka-client).

Au démarrage : « je suis RENTAL-ML, joignable sur host.docker.internal:8107 ».
Ensuite, un battement de cœur toutes les 30 s ; à l'arrêt, désinscription.
La Gateway (route lb://RENTAL-ML) trouve ainsi l'adresse du service.
"""
import logging

import py_eureka_client.eureka_client as eureka_client

from app.settings import settings

EUREKA_APP_NAME = settings.eureka_app_name          # RENTAL-ML (Config Server : service.eureka-name)
logger = logging.getLogger("ml-service")


def log_eureka_error(error_type: str, error: Exception) -> None:
    """Eureka injoignable : le service continue de fonctionner, py-eureka-client réessaie tout seul."""
    logger.warning("Eureka (%s) : %s", error_type, error)


async def register_in_eureka() -> None:
    if not settings.eureka_enabled:
        logger.info("Inscription Eureka désactivée (EUREKA_ENABLED=false)")
        return
    service_base_url = f"http://{settings.service_host}:{settings.service_port}"
    try:
        await eureka_client.init_async(
            eureka_server=settings.eureka_url,
            app_name=EUREKA_APP_NAME,
            # Convention de l'équipe : nom host.docker.internal, jamais une IP détectée automatiquement
            # (sinon ipAddr vaut 127.0.0.1, qui désigne le conteneur lui-même vu depuis la Gateway)
            instance_host=settings.service_host,
            instance_ip=settings.service_host,
            instance_id=f"{settings.service_host}:{EUREKA_APP_NAME.lower()}:{settings.service_port}",
            instance_port=settings.service_port,
            health_check_url=f"{service_base_url}/health",
            status_page_url=f"{service_base_url}/docs",
            home_page_url=service_base_url,
            should_discover=False,      # on s'inscrit seulement ; on n'appelle pas encore d'autres services
            on_error=log_eureka_error,
        )
        logger.info("Inscrit dans Eureka : %s -> %s", EUREKA_APP_NAME, service_base_url)
    except Exception as error:  # Eureka éteint : le service doit démarrer quand même
        logger.warning("Inscription Eureka impossible (%s) : le service démarre sans Eureka", error)


async def unregister_from_eureka() -> None:
    if settings.eureka_enabled:
        try:
            await eureka_client.stop_async()
        except Exception as error:
            logger.warning("Désinscription Eureka impossible : %s", error)
