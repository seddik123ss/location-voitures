"""
Startup safety checks called from config/settings.py (pure functions, unit-tested).
"""
from django.core.exceptions import ImproperlyConfigured

AUTH_MODES = ("jwt", "dev")


def check_auth_mode(auth_mode: str, debug: bool, testing: bool) -> None:
    """
    AUTH_MODE=dev trusts the X-User-Id / X-User-Roles headers: anyone could claim to be ADMIN.
    It is therefore refused outside local development (DJANGO_DEBUG=false), except for the
    test runner, which always uses it. The service then refuses to start.
    """
    if auth_mode not in AUTH_MODES:
        raise ImproperlyConfigured(f"AUTH_MODE={auth_mode!r} invalide (attendu : jwt | dev)")
    if auth_mode == "dev" and not debug and not testing:
        raise ImproperlyConfigured(
            "AUTH_MODE=dev est interdit quand DJANGO_DEBUG=false : les en-têtes X-User-Id / X-User-Roles "
            "permettraient à n'importe qui de se faire passer pour un ADMIN. Utiliser AUTH_MODE=jwt."
        )
