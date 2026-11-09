"""
Eureka registration and discovery (py-eureka-client), started from PaymentsConfig.ready().

- Registration: app RENTAL-PAYMENT, announced as SERVICE_HOST (host.docker.internal, per
  docs/conventions.md: never an auto-detected IP), port SERVICE_PORT, health check /health.
  Only the process that actually serves HTTP registers: `runserver` (the autoreloader's
  child process, RUN_MAIN=true, or --noreload). migrate, test, shell, the RabbitMQ consumer... do not.
- Discovery: any process can resolve another service; a discovery-only client is created
  lazily when needed (should_register=False).
If Eureka is down at startup, the service still starts (error logged); calls to other
services then answer 503, as required by the conventions.
"""
import atexit
import logging
import os
import sys
import threading

from django.conf import settings
from py_eureka_client import eureka_client

logger = logging.getLogger(__name__)

_client = None
_lock = threading.Lock()


def _serves_http() -> bool:
    argv = sys.argv
    if len(argv) < 2 or argv[1] != "runserver":
        return False
    return os.environ.get("RUN_MAIN") == "true" or "--noreload" in argv


def _instance_kwargs() -> dict:
    host, port = settings.SERVICE_HOST, settings.SERVICE_PORT
    base = f"http://{host}:{port}"
    return dict(
        app_name=settings.SERVICE_NAME,
        instance_id=f"{host}:{settings.SERVICE_NAME.lower()}:{port}",
        instance_host=host,
        instance_ip=host,  # explicit: no IP auto-detection
        instance_port=port,
        home_page_url=f"{base}/swagger",
        status_page_url=f"{base}/health",
        health_check_url=f"{base}/health",
        renewal_interval_in_secs=10,
        duration_in_secs=30,
    )


def start() -> None:
    """Register this instance in Eureka (called from AppConfig.ready())."""
    global _client
    if not settings.EUREKA_ENABLED or not _serves_http():
        return
    with _lock:
        if _client is not None:
            return
        try:
            _client = eureka_client.init(eureka_server=settings.EUREKA_URL, **_instance_kwargs())
        except Exception as exc:  # noqa: BLE001 - never prevent the service from starting
            logger.error("Eureka registration failed (%s): %s. The service runs, but is not "
                         "reachable through the Gateway.", settings.EUREKA_URL, exc)
            return
    logger.info("Registered in Eureka as %s (%s:%s)", settings.SERVICE_NAME,
                settings.SERVICE_HOST, settings.SERVICE_PORT)
    atexit.register(stop)


def stop() -> None:
    """Unregister on shutdown so the Gateway stops routing here immediately."""
    global _client
    with _lock:
        if _client is not None:
            try:
                _client.stop()
            except Exception:  # noqa: BLE001
                pass
            _client = None


def get_client():
    """Eureka client for discovery: the registered one, or a discovery-only client."""
    global _client
    if not settings.EUREKA_ENABLED:
        return None
    with _lock:
        if _client is None:
            _client = eureka_client.init(eureka_server=settings.EUREKA_URL, should_register=False,
                                         app_name=settings.SERVICE_NAME)
        return _client
