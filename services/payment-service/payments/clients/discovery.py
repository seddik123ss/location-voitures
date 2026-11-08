"""
Service discovery: Eureka application name -> base URL of one healthy instance.

Step 3 replaces the body of resolve_service_url() with a py-eureka-client lookup.
Until then it always raises ServiceNotFound, which callers translate to 503
("service stopped or absent from Eureka"). No hard-coded URLs, per the conventions.
"""


class ServiceNotFound(Exception):
    """No instance of the requested application is registered in Eureka."""


def resolve_service_url(app_name: str) -> str:
    raise ServiceNotFound(f"{app_name} introuvable : découverte Eureka non configurée (étape 3)")
