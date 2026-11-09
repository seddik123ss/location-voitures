"""Prédiction de prix : réponse réussie, erreurs 422 au format de l'équipe, 503 sans modèle actif.
(« Aujourd'hui » est fixé au 2026-11-09 dans conftest.py.)"""

SUV_ID = "a6ec349d-0ff0-4cb1-bfb9-453dd8e2ee8c"
SOUSSE_ID = "1deb1dea-ab54-436d-8ed7-c3322f782c88"
VALID_REQUEST = {"categoryId": SUV_ID, "agencyId": SOUSSE_ID, "startDate": "2026-12-18", "endDate": "2026-12-22"}


def error_fields(response) -> dict:
    """{champ: message} à partir de details[] d'une réponse d'erreur."""
    return {detail["field"]: detail["message"] for detail in response.json()["details"]}


def test_prediction_returns_price_inside_interval_with_three_decimals(client, run_sql):
    response = client.post("/api/ml/price-predictions", json=VALID_REQUEST)

    assert response.status_code == 200
    prediction = response.json()
    assert prediction["currency"] == "TND"
    assert prediction["modelVersion"] == 1
    assert prediction["lowerBound"] < prediction["dailyPrice"] < prediction["upperBound"]
    assert 100 < prediction["dailyPrice"] < 250          # SUV (tarif de base 160) en saison moyenne
    for amount in ("dailyPrice", "lowerBound", "upperBound"):
        assert round(prediction[amount], 3) == prediction[amount]
    assert run_sql("SELECT count(*) FROM prediction_logs") == 1


def test_internal_route_returns_same_price_as_public_route(client, internal_headers):
    public_response = client.post("/api/ml/price-predictions", json=VALID_REQUEST)          # token ADMIN
    internal_response = client.post("/internal/ml/price-predictions", json=VALID_REQUEST,
                                    headers=internal_headers)                               # token INTERNAL

    assert internal_response.status_code == 200
    assert internal_response.json() == public_response.json()


def test_business_errors_return_422_with_one_detail_per_field(client):
    response = client.post("/api/ml/price-predictions", json={
        "categoryId": "00000000-0000-4000-8000-000000000000",   # catégorie inconnue
        "agencyId": SOUSSE_ID,
        "startDate": "2026-10-01",                              # dans le passé
        "endDate": "2026-09-28",                                # avant le départ
        "occupancyRate": 1.5,                                   # hors de 0..1
    })

    assert response.status_code == 422
    body = response.json()
    assert body["status"] == 422 and body["error"] == "UNPROCESSABLE_ENTITY"
    assert error_fields(response) == {
        "categoryId": "catégorie inconnue",
        "startDate": "la date de départ est dans le passé",
        "endDate": "la date de retour doit être après la date de départ",
        "occupancyRate": "doit être compris entre 0 et 1",
    }


def test_too_far_departure_and_too_long_rental_return_422(client):
    response = client.post("/api/ml/price-predictions", json={
        **VALID_REQUEST, "startDate": "2027-08-01", "endDate": "2027-09-15"})   # > 180 jours, 45 jours

    assert response.status_code == 422
    assert error_fields(response) == {
        "startDate": "la date de départ doit être dans les 180 prochains jours",
        "endDate": "durée maximale : 30 jours",
    }


def test_malformed_request_returns_422_in_team_error_format(client):
    response = client.post("/api/ml/price-predictions", json={
        "categoryId": SUV_ID, "startDate": "18/12/2026", "endDate": "2026-12-22", "price": 100})

    assert response.status_code == 422
    assert set(response.json()) == {"status", "error", "message", "details"}
    assert error_fields(response) == {
        "agencyId": "champ obligatoire",
        "startDate": "date invalide (format attendu : AAAA-MM-JJ)",
        "price": "champ non autorisé",
    }


def test_prediction_without_active_model_returns_503(client, run_sql):
    run_sql("UPDATE model_versions SET active = false")

    response = client.post("/api/ml/price-predictions", json=VALID_REQUEST)

    assert response.status_code == 503
    assert response.json()["error"] == "SERVICE_UNAVAILABLE"
    assert run_sql("SELECT count(*) FROM prediction_logs") == 0
