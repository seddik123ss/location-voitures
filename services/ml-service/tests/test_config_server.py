"""Lecture de la configuration depuis le Config Server : présent, prioritaire après l'environnement, absent."""
import json
import logging
import re
import socket
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer

import pytest

from app.settings import SERVICE_DIR, load_settings

# Réponse au format de Spring Cloud Config (GET /rental-ml/default) : le plus prioritaire en premier
CONFIG_SERVER_RESPONSE = {
    "name": "rental-ml", "profiles": ["default"], "label": None,
    "propertySources": [
        {"name": "file:/config-repo/rental-ml.yml",
         "source": {"service.port": 8107, "service.eureka-name": "RENTAL-ML-CONFIG", "ml.rabbitmq.queue": "ml.queue-from-config"}},
        {"name": "file:/config-repo/application.yml",
         "source": {"service.host": "host.docker.internal", "service.eureka-name": "IGNORED-COMMON-NAME",
                    "eureka.url": "http://eureka-from-config:8761/eureka",
                    "keycloak.issuer": "http://keycloak-from-config/realms/location-voitures"}},
    ],
}


@pytest.fixture
def fake_config_server():
    """Vrai petit serveur HTTP local qui répond comme le Config Server ; renvoie son URL."""
    requested_paths = []

    class Handler(BaseHTTPRequestHandler):
        def do_GET(self):
            requested_paths.append(self.path)
            body = json.dumps(CONFIG_SERVER_RESPONSE).encode()
            self.send_response(200 if self.path == "/rental-ml/default" else 404)
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(body)

        def log_message(self, *args):
            pass

    server = HTTPServer(("127.0.0.1", 0), Handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    yield f"http://127.0.0.1:{server.server_port}", requested_paths
    server.shutdown()


def test_config_server_values_are_used_when_present(fake_config_server, monkeypatch):
    url, requested_paths = fake_config_server
    monkeypatch.setenv("CONFIG_SERVER_URL", url)

    settings, origin = load_settings(_env_file=None)

    assert requested_paths == ["/rental-ml/default"]
    assert "Config Server" in origin
    assert settings.eureka_app_name == "RENTAL-ML-CONFIG"            # rental-ml.yml l'emporte sur application.yml
    assert settings.rabbitmq_queue == "ml.queue-from-config"
    assert settings.eureka_url == "http://eureka-from-config:8761/eureka"
    assert settings.keycloak_issuer == "http://keycloak-from-config/realms/location-voitures"


def test_environment_and_env_file_override_config_server(fake_config_server, monkeypatch, tmp_path):
    url, _ = fake_config_server
    monkeypatch.setenv("CONFIG_SERVER_URL", url)
    monkeypatch.setenv("EUREKA_URL", "http://eureka-from-environment:8761/eureka")
    env_file = tmp_path / ".env"
    env_file.write_text("SERVICE_PORT=9107\nKEYCLOAK_CLIENT_SECRET=secret-local\n", encoding="utf-8")

    settings, _ = load_settings(_env_file=env_file)

    assert settings.eureka_url == "http://eureka-from-environment:8761/eureka"   # environnement > Config Server
    assert settings.service_port == 9107                                           # .env > Config Server
    assert settings.keycloak_client_secret == "secret-local"                       # secret : uniquement local
    assert settings.eureka_app_name == "RENTAL-ML-CONFIG"                          # absent en local : Config Server


def test_unreachable_config_server_warns_and_starts_with_local_values(monkeypatch, caplog):
    # Les migrations Alembic des tests (logging.config.fileConfig) désactivent les loggers existants : on réactive le nôtre
    monkeypatch.setattr(logging.getLogger("ml-service"), "disabled", False)
    with socket.socket() as probe:                     # port libre, sans serveur derrière
        probe.bind(("127.0.0.1", 0))
        closed_port = probe.getsockname()[1]
    monkeypatch.setenv("CONFIG_SERVER_URL", f"http://127.0.0.1:{closed_port}")

    with caplog.at_level("WARNING", logger="ml-service"):
        settings, origin = load_settings(_env_file=None)

    assert "Config Server injoignable" in caplog.text
    assert "injoignable" in origin
    assert settings.eureka_app_name == "RENTAL-ML"                 # valeurs par défaut du code
    assert settings.rabbitmq_queue == "ml.reservation_completed"


def test_config_repo_contains_no_secret():
    config_repo = SERVICE_DIR.parent.parent / "infra" / "config-repo"
    files = list(config_repo.glob("*.yml"))

    assert {file.name for file in files} >= {"application.yml", "rental-ml.yml"}
    for file in files:
        text = file.read_text(encoding="utf-8").lower()
        values = [line.split(":", 1)[1] for line in text.splitlines() if ":" in line and not line.strip().startswith("#")]
        assert not any(re.search(r"(secret|password|pwd)\s*:", line) for line in text.splitlines()
                       if not line.strip().startswith("#")), f"clé secrète dans {file.name}"
        assert not any(re.search(r"://[^/\s]+:[^/\s]+@", value) for value in values), f"identifiants dans une URL : {file.name}"
