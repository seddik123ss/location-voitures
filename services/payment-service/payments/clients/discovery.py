"""
Service discovery: Eureka application name -> base URL of one UP instance.
No hard-coded URL (docs/conventions.md). Any failure raises ServiceNotFound, which the
HTTP clients translate to 503 ("service stopped or absent from Eureka").
"""
import logging
import random

logger = logging.getLogger(__name__)


class ServiceNotFound(Exception):
    """No UP instance of the requested application is registered in Eureka."""


def resolve_service_url(app_name: str) -> str:
    from payments import eureka

    try:
        client = eureka.get_client()
    except Exception as exc:  # noqa: BLE001 - Eureka down when creating the client
        raise ServiceNotFound(f"Eureka injoignable : {exc}")
    if client is None:
        raise ServiceNotFound("Découverte Eureka désactivée (EUREKA_ENABLED=false)")
    try:
        instances = client.applications.get_application(app_name).up_instances
    except Exception as exc:  # noqa: BLE001 - registry fetch failed
        raise ServiceNotFound(f"Registre Eureka illisible : {exc}")
    if not instances:
        raise ServiceNotFound(f"{app_name} n'est pas enregistré (ou pas UP) dans Eureka")
    instance = random.choice(instances)  # simple client-side load balancing
    return f"http://{instance.hostName}:{instance.port.port}"
