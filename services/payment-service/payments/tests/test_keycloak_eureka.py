"""
JWT authentication, service token and Eureka discovery, without Keycloak or Eureka:
tokens are signed with an RSA key generated for the test; JWKS/Eureka/HTTP are mocked.
"""
import time
import uuid
from types import SimpleNamespace
from unittest import mock

import jwt
import requests
from cryptography.hazmat.primitives.asymmetric import rsa
from django.conf import settings
from django.test import SimpleTestCase, override_settings
from rest_framework.response import Response
from rest_framework.test import APIRequestFactory
from rest_framework.views import APIView

from payments.auth import Principal, has_role
from payments.clients import discovery
from payments.demo_data import CLIENT1
from payments.exceptions import ServiceUnavailableError
from payments.keycloak import KeycloakJWTAuthentication, ServiceTokenProvider

KEY = rsa.generate_private_key(public_exponent=65537, key_size=2048)
OTHER_KEY = rsa.generate_private_key(public_exponent=65537, key_size=2048)


def make_token(key=KEY, **overrides):
    now = int(time.time())
    claims = {"iss": settings.KEYCLOAK_ISSUER, "sub": str(CLIENT1), "iat": now, "exp": now + 300,
              "aud": "account", "azp": "rental-front",
              "roles": ["default-roles-location-voitures", "offline_access", "CLIENT"], **overrides}
    claims = {k: v for k, v in claims.items() if v is not None}
    return jwt.encode(claims, key, algorithm="RS256", headers={"kid": "test-kid"})


class _ProtectedView(APIView):
    authentication_classes = [KeycloakJWTAuthentication]
    permission_classes = [has_role("CLIENT")]

    def get(self, request):
        return Response({"userId": str(request.user.user_id), "roles": sorted(request.user.roles)})


class KeycloakJWTAuthenticationTests(SimpleTestCase):
    def setUp(self):
        fake_jwks = mock.Mock()
        fake_jwks.get_signing_key_from_jwt.return_value = SimpleNamespace(key=KEY.public_key())
        patcher = mock.patch("payments.keycloak.jwk_client", return_value=fake_jwks)
        patcher.start()
        self.addCleanup(patcher.stop)
        self.jwks = fake_jwks

    def call(self, authorization=None):
        headers = {"HTTP_AUTHORIZATION": authorization} if authorization else {}
        return _ProtectedView.as_view()(APIRequestFactory().get("/x", **headers))

    def test_valid_token_gives_principal_with_filtered_roles(self):
        response = self.call(f"Bearer {make_token()}")
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.data, {"userId": str(CLIENT1), "roles": ["CLIENT"]})

    def test_missing_token_is_401_with_bearer_challenge(self):
        response = self.call()
        self.assertEqual(response.status_code, 401)
        self.assertEqual(response["WWW-Authenticate"], 'Bearer realm="location-voitures"')
        self.assertEqual(response.data["error"], "UNAUTHORIZED")

    def test_rejected_tokens_are_401(self):
        cases = {
            "expired": make_token(exp=int(time.time()) - 120),
            "other issuer": make_token(iss="http://evil/realms/location-voitures"),
            "bad signature": make_token(key=OTHER_KEY),
            "no sub": make_token(sub=None),
            "not a jwt": "abc.def",
        }
        for name, token in cases.items():
            with self.subTest(name):
                self.assertEqual(self.call(f"Bearer {token}").status_code, 401)
        self.assertEqual(self.call(f"Basic {make_token()}").status_code, 401)

    def test_hs256_token_is_refused(self):
        token = jwt.encode({"iss": settings.KEYCLOAK_ISSUER, "sub": str(CLIENT1), "iat": 1, "exp": 9999999999},
                           "secret-guess-123456789012345678901234", algorithm="HS256")
        self.assertEqual(self.call(f"Bearer {token}").status_code, 401)

    def test_wrong_role_is_403(self):
        response = self.call(f"Bearer {make_token(roles=['AGENT'])}")
        self.assertEqual(response.status_code, 403)

    def test_keycloak_unreachable_is_503(self):
        self.jwks.get_signing_key_from_jwt.side_effect = jwt.PyJWKClientConnectionError("refused")
        response = self.call(f"Bearer {make_token()}")
        self.assertEqual(response.status_code, 503)
        self.assertEqual(response.data["error"], "SERVICE_UNAVAILABLE")


@override_settings(KEYCLOAK_CLIENT_ID="payment-service", KEYCLOAK_CLIENT_SECRET="s3cret")
class ServiceTokenProviderTests(SimpleTestCase):
    def make(self, *responses):
        session = mock.Mock(spec=requests.Session)
        session.post.side_effect = list(responses)
        self.clock = [1000.0]
        return ServiceTokenProvider(session=session, now=lambda: self.clock[0]), session

    @staticmethod
    def ok(token, expires_in=300):
        return mock.Mock(status_code=200, json=lambda: {"access_token": token, "expires_in": expires_in})

    def test_token_is_requested_with_client_credentials_and_cached(self):
        provider, session = self.make(self.ok("t1"))
        self.assertEqual(provider(), "t1")
        self.assertEqual(provider(), "t1")
        session.post.assert_called_once()
        data = session.post.call_args.kwargs["data"]
        self.assertEqual(data, {"grant_type": "client_credentials", "client_id": "payment-service",
                                "client_secret": "s3cret"})
        self.assertTrue(session.post.call_args.args[0].endswith("/protocol/openid-connect/token"))

    def test_token_is_renewed_before_expiry(self):
        provider, session = self.make(self.ok("t1", 300), self.ok("t2", 300))
        provider()
        self.clock[0] += 280  # less than 30 s left
        self.assertEqual(provider(), "t2")

    def test_keycloak_down_or_refusing_is_503(self):
        for failure in (requests.ConnectionError("refused"), mock.Mock(status_code=401, text="invalid_client")):
            provider, _ = self.make(failure)
            with self.subTest(failure=failure), self.assertRaises(ServiceUnavailableError):
                provider()


def fake_eureka(instances):
    app = SimpleNamespace(up_instances=instances)
    return SimpleNamespace(applications=SimpleNamespace(get_application=lambda name: app))


class DiscoveryTests(SimpleTestCase):
    def test_resolves_up_instance_by_host_name(self):
        instance = SimpleNamespace(hostName="host.docker.internal", port=SimpleNamespace(port=8103))
        with mock.patch("payments.eureka.get_client", return_value=fake_eureka([instance])):
            self.assertEqual(discovery.resolve_service_url("RENTAL-RESERVATION"), "http://host.docker.internal:8103")

    def test_absent_service_eureka_down_or_disabled_raise_service_not_found(self):
        cases = {
            "absent": mock.patch("payments.eureka.get_client", return_value=fake_eureka([])),
            "eureka down": mock.patch("payments.eureka.get_client", side_effect=ConnectionError("refused")),
            "disabled": mock.patch("payments.eureka.get_client", return_value=None),
        }
        for name, patcher in cases.items():
            with self.subTest(name), patcher, self.assertRaises(discovery.ServiceNotFound):
                discovery.resolve_service_url("RENTAL-RESERVATION")


class EurekaRegistrationTests(SimpleTestCase):
    def test_only_the_http_serving_process_registers(self):
        from payments import eureka
        cases = [
            (["manage.py", "runserver", "0.0.0.0:8104"], {"RUN_MAIN": "true"}, True),
            (["manage.py", "runserver", "0.0.0.0:8104"], {}, False),  # autoreloader parent
            (["manage.py", "runserver", "--noreload"], {}, True),
            (["manage.py", "migrate"], {"RUN_MAIN": "true"}, False),
            (["manage.py", "consume_events"], {}, False),
        ]
        for argv, env, expected in cases:
            with self.subTest(argv=argv, env=env), mock.patch("sys.argv", argv), \
                    mock.patch.dict("os.environ", env, clear=False):
                if "RUN_MAIN" not in env:
                    __import__("os").environ.pop("RUN_MAIN", None)
                self.assertEqual(eureka._serves_http(), expected)

    @override_settings(SERVICE_HOST="host.docker.internal", SERVICE_PORT=8104)
    def test_announces_host_docker_internal_never_an_ip(self):
        from payments import eureka
        kwargs = eureka._instance_kwargs()
        self.assertEqual((kwargs["app_name"], kwargs["instance_host"], kwargs["instance_ip"], kwargs["instance_port"]),
                         ("RENTAL-PAYMENT", "host.docker.internal", "host.docker.internal", 8104))
        self.assertEqual(kwargs["health_check_url"], "http://host.docker.internal:8104/health")


class PrincipalTests(SimpleTestCase):
    def test_staff_like(self):
        self.assertTrue(Principal(uuid.uuid4(), frozenset({"AGENT"})).is_staff_like)
        self.assertFalse(Principal(uuid.uuid4(), frozenset({"CLIENT"})).is_staff_like)
