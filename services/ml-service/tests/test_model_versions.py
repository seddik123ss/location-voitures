"""Versions du modèle : pagination, 404/400, 409, entraînement + activation, séquence des numéros."""
from tests.conftest import DEMO_VERSION_ID


def test_list_models_returns_team_pagination_format(client):
    response = client.get("/api/ml/models", params={"page": 0, "size": 20})

    assert response.status_code == 200
    page = response.json()
    assert set(page) == {"items", "page", "size", "total"}
    assert (page["page"], page["size"], page["total"]) == (0, 20, 1)
    assert page["items"][0]["version"] == 1 and page["items"][0]["active"] is True
    assert client.get("/api/ml/models", params={"page": -1}).status_code == 400


def test_unknown_version_returns_404_and_invalid_id_returns_400(client):
    unknown_response = client.get("/api/ml/models/11111111-1111-4111-8111-111111111111")
    invalid_response = client.get("/api/ml/models/abc")

    assert unknown_response.status_code == 404
    assert unknown_response.json()["error"] == "NOT_FOUND"
    assert invalid_response.status_code == 400
    assert invalid_response.json()["details"] == [{"field": "id", "message": "UUID invalide"}]


def test_deleting_active_version_returns_409(client, temporary_artifacts_dir):
    response = client.delete(f"/api/ml/models/{DEMO_VERSION_ID}")

    assert response.status_code == 409
    assert response.json()["error"] == "CONFLICT"
    assert client.get(f"/api/ml/models/{DEMO_VERSION_ID}").status_code == 200
    assert (temporary_artifacts_dir / "price_model_v1.joblib").exists()


def test_training_then_activation_keeps_a_single_active_version(client, run_sql, temporary_artifacts_dir):
    training_response = client.post("/api/ml/trainings")

    assert training_response.status_code == 201
    new_version = training_response.json()
    assert training_response.headers["Location"] == f"/api/ml/models/{new_version['id']}"
    assert (new_version["version"], new_version["active"]) == (2, False)
    assert (temporary_artifacts_dir / "price_model_v2.joblib").exists()

    activation_response = client.post(f"/api/ml/models/{new_version['id']}/activate")

    assert activation_response.status_code == 200 and activation_response.json()["active"] is True
    assert client.get(f"/api/ml/models/{DEMO_VERSION_ID}").json()["active"] is False
    assert run_sql("SELECT count(*) FROM model_versions WHERE active") == 1
    prediction = client.post("/api/ml/price-predictions", json={
        "categoryId": "a6ec349d-0ff0-4cb1-bfb9-453dd8e2ee8c", "agencyId": "1deb1dea-ab54-436d-8ed7-c3322f782c88",
        "startDate": "2026-12-18", "endDate": "2026-12-22"})
    assert prediction.json()["modelVersion"] == 2


def test_version_number_is_never_reused_after_deletion(client, temporary_artifacts_dir):
    second_version = client.post("/api/ml/trainings").json()
    assert second_version["version"] == 2

    delete_response = client.delete(f"/api/ml/models/{second_version['id']}")
    assert delete_response.status_code == 204
    assert not (temporary_artifacts_dir / "price_model_v2.joblib").exists()

    third_version = client.post("/api/ml/trainings").json()
    assert third_version["version"] == 3
