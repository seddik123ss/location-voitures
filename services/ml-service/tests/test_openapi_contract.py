"""Le contrat docs/openapi/ml-service.yaml doit rester cohérent avec le Swagger généré par le code."""
import yaml

from app.settings import SERVICE_DIR
from main import app

CONTRACT_PATH = SERVICE_DIR.parent.parent / "docs" / "openapi" / "ml-service.yaml"
HTTP_METHODS = {"get", "post", "put", "delete", "patch"}
# Documentés dans le contrat, mais renvoyés seulement quand Keycloak sera branché
SECURITY_CODES_NOT_YET_IMPLEMENTED = {"401", "403"}


def operations_with_status_codes(openapi_document: dict, ignored_codes: set[str] = frozenset()) -> dict:
    """{(chemin, méthode): {codes de réponse}} pour toutes les opérations d'un document OpenAPI."""
    return {
        (path, method): set(operation["responses"]) - ignored_codes
        for path, path_item in openapi_document["paths"].items()
        for method, operation in path_item.items()
        if method in HTTP_METHODS
    }


def test_contract_yaml_matches_generated_swagger():
    contract = yaml.safe_load(CONTRACT_PATH.read_text(encoding="utf-8"))
    generated = app.openapi()

    in_contract = operations_with_status_codes(contract, SECURITY_CODES_NOT_YET_IMPLEMENTED)
    in_code = operations_with_status_codes(generated)

    assert contract["openapi"] == "3.0.3"
    assert set(in_contract) == set(in_code), "routes ou méthodes différentes entre le YAML et le code"
    for operation, codes in in_code.items():
        assert in_contract[operation] == codes, f"codes de réponse différents pour {operation}"
    for (path, method), _ in in_contract.items():
        if path != "/health":
            assert contract["paths"][path][method].get("x-roles"), f"x-roles manquant pour {method.upper()} {path}"
