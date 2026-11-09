"""Le contrat docs/openapi/ml-service.yaml doit rester cohérent avec le Swagger généré par le code."""
import yaml

from app.settings import SERVICE_DIR
from main import app

CONTRACT_PATH = SERVICE_DIR.parent.parent / "docs" / "openapi" / "ml-service.yaml"
HTTP_METHODS = {"get", "post", "put", "delete", "patch"}


def operations_with_status_codes(openapi_document: dict) -> dict:
    """{(chemin, méthode): {codes de réponse}} pour toutes les opérations d'un document OpenAPI."""
    return {
        (path, method): set(operation["responses"])
        for path, path_item in openapi_document["paths"].items()
        for method, operation in path_item.items()
        if method in HTTP_METHODS
    }


def expected_roles(path: str) -> list[str]:
    """Règle de l'équipe : ADMIN pour /api/ml, INTERNAL pour /internal, /health public."""
    return ["INTERNAL"] if path.startswith("/internal/") else ["ADMIN"]


def test_contract_yaml_matches_generated_swagger():
    contract = yaml.safe_load(CONTRACT_PATH.read_text(encoding="utf-8"))
    generated = app.openapi()

    in_contract = operations_with_status_codes(contract)
    in_code = operations_with_status_codes(generated)

    assert contract["openapi"] == "3.0.3"
    assert set(in_contract) == set(in_code), "routes ou méthodes différentes entre le YAML et le code"
    for operation, codes in in_code.items():
        assert in_contract[operation] == codes, f"codes de réponse différents pour {operation}"


def test_contract_roles_and_security_match_the_code():
    contract = yaml.safe_load(CONTRACT_PATH.read_text(encoding="utf-8"))
    generated = app.openapi()

    for (path, method) in operations_with_status_codes(contract):
        contract_operation = contract["paths"][path][method]
        code_operation = generated["paths"][path][method]
        if path == "/health":
            assert contract_operation.get("security") == [] and "security" not in code_operation
        else:
            assert contract_operation.get("x-roles") == expected_roles(path), f"x-roles de {method.upper()} {path}"
            assert code_operation.get("security") == [{"bearerAuth": []}], f"route non protégée : {path}"
