import json
from unittest import mock

from django.test import SimpleTestCase

from payments import config_server

# Réponse de GET /rental-payment/default : le fichier spécifique d'abord (le plus prioritaire), puis application.yml.
CONFIG_SERVER_RESPONSE = {
    "name": "rental-payment",
    "profiles": ["default"],
    "propertySources": [
        {"name": "file:/config-repo/rental-payment.yml",
         "source": {"service.port": 8104, "service.eureka-name": "RENTAL-PAYMENT"}},
        {"name": "file:/config-repo/application.yml",
         "source": {"service.host": "host.docker.internal", "eureka.url": "http://localhost:8761/eureka",
                    "keycloak.issuer": "http://localhost:8180/realms/location-voitures", "service.port": 9999}},
    ],
}


class FakeResponse:
    def __init__(self, document):
        self.payload = json.dumps(document).encode()

    def read(self, *args):
        return self.payload

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False


class ConfigServerTests(SimpleTestCase):
    def test_reads_the_four_non_secret_values_specific_file_wins(self):
        with mock.patch("urllib.request.urlopen", return_value=FakeResponse(CONFIG_SERVER_RESPONSE)) as urlopen:
            values = config_server.fetch_values("http://localhost:8888/")
        self.assertEqual(urlopen.call_args.args[0], "http://localhost:8888/rental-payment/default")
        self.assertEqual(values, {
            "SERVICE_PORT": "8104",   # rental-payment.yml l'emporte sur application.yml
            "SERVICE_HOST": "host.docker.internal",
            "EUREKA_URL": "http://localhost:8761/eureka",
            "KEYCLOAK_ISSUER": "http://localhost:8180/realms/location-voitures",
        })

    def test_only_missing_variables_are_filled(self):
        environ = {"EUREKA_URL": "http://eureka:8761/eureka"}   # fixé par le .env ou docker-compose
        with mock.patch("urllib.request.urlopen", return_value=FakeResponse(CONFIG_SERVER_RESPONSE)):
            applied = config_server.apply_to_environment("http://localhost:8888", environ)
        self.assertEqual(environ["EUREKA_URL"], "http://eureka:8761/eureka")
        self.assertEqual(environ["SERVICE_PORT"], "8104")
        self.assertNotIn("EUREKA_URL", applied)

    def test_unreachable_config_server_changes_nothing(self):
        environ = {}
        with mock.patch("urllib.request.urlopen", side_effect=OSError("connection refused")), \
                self.assertLogs("payments.config_server", "WARNING"):
            self.assertEqual(config_server.apply_to_environment("http://localhost:8888", environ), {})
        self.assertEqual(environ, {})

    def test_disabled_when_url_is_empty(self):
        with mock.patch("urllib.request.urlopen") as urlopen:
            self.assertEqual(config_server.apply_to_environment("", {}), {})
        urlopen.assert_not_called()
