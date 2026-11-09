"""Sécurité Keycloak : 401 sans token ou token invalide, 403 sans le bon rôle, /health public."""
import jwt

from app import keycloak_security
from tests.conftest import OTHER_SIGNING_KEY, bearer, create_token

PREDICTION_REQUEST = {"categoryId": "a6ec349d-0ff0-4cb1-bfb9-453dd8e2ee8c",
                      "agencyId": "1deb1dea-ab54-436d-8ed7-c3322f782c88",
                      "startDate": "2026-12-18", "endDate": "2026-12-22"}


def test_request_without_token_returns_401_in_team_format(anonymous_client):
    response = anonymous_client.get("/api/ml/models")

    assert response.status_code == 401
    assert response.headers["WWW-Authenticate"] == "Bearer"
    assert response.json() == {
        "status": 401, "error": "UNAUTHORIZED",
        "message": "Token absent : en-tête Authorization: Bearer <token> attendu", "details": []}


def test_expired_forged_or_foreign_token_returns_401(anonymous_client):
    expired_token = create_token(["ADMIN"], expires_in_seconds=-60)
    forged_token = create_token(["ADMIN"], signing_key=OTHER_SIGNING_KEY)        # pas signé par « Keycloak »
    foreign_token = create_token(["ADMIN"], issuer="http://pirate.example/realms/x")

    expired_response = anonymous_client.get("/api/ml/models", headers=bearer(expired_token))
    assert expired_response.status_code == 401
    assert expired_response.json()["message"] == "Token expiré : reconnectez-vous"
    for invalid_token in (forged_token, foreign_token, "pas-un-jwt"):
        response = anonymous_client.get("/api/ml/models", headers=bearer(invalid_token))
        assert response.status_code == 401
        assert response.json()["message"] == "Token invalide"


def test_client_role_gets_403_on_admin_routes(anonymous_client):
    client_headers = bearer(create_token(["CLIENT"]))

    for method, path in (("GET", "/api/ml/models"), ("POST", "/api/ml/trainings")):
        response = anonymous_client.request(method, path, headers=client_headers)
        assert response.status_code == 403
        assert response.json()["message"] == "Rôle insuffisant : ADMIN requis"


def test_internal_route_accepts_internal_role_and_rejects_admin(anonymous_client, internal_headers):
    admin_headers = bearer(create_token(["ADMIN"]))

    internal_ok = anonymous_client.post("/internal/ml/price-predictions", json=PREDICTION_REQUEST,
                                        headers=internal_headers)
    admin_refused = anonymous_client.post("/internal/ml/price-predictions", json=PREDICTION_REQUEST,
                                          headers=admin_headers)
    internal_refused_on_public = anonymous_client.get("/api/ml/models", headers=internal_headers)

    assert internal_ok.status_code == 200
    assert admin_refused.status_code == 403
    assert admin_refused.json()["message"] == "Rôle insuffisant : INTERNAL requis"
    assert internal_refused_on_public.status_code == 403


def test_unreachable_keycloak_returns_503(anonymous_client, monkeypatch):
    def keycloak_down(token):
        raise jwt.PyJWKClientConnectionError("connexion refusée")
    monkeypatch.setattr(keycloak_security, "get_signing_key", keycloak_down)

    response = anonymous_client.get("/api/ml/models", headers=bearer(create_token(["ADMIN"])))

    assert response.status_code == 503
    assert response.json()["message"] == "Keycloak injoignable : impossible de vérifier le token"


def test_health_is_public(anonymous_client):
    response = anonymous_client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"status": "UP"}
