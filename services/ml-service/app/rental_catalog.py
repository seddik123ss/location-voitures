"""Catalogue PROVISOIRE des catégories et agences connues du service.

C'est le SEUL endroit à modifier si l'équipe change la liste.
Les UUID et tarifs viennent de docs/demo-ids.md (vehicle-service, Yosra).
"""

# Catégories de véhicules : UUID -> informations
CATEGORIES: dict[str, dict] = {
    "94236cd0-16fc-41e0-bfc2-c8b1d2c8b1fc": {"code": "ECONOMIQUE", "name": "Économique", "daily_rate": 90.0},
    "a6ec349d-0ff0-4cb1-bfb9-453dd8e2ee8c": {"code": "SUV", "name": "SUV", "daily_rate": 160.0},
    "98d95d25-4112-4e52-8fbf-d2d908239b34": {"code": "LUXE", "name": "Luxe", "daily_rate": 300.0},
}

# Agences : UUID -> informations
AGENCIES: dict[str, dict] = {
    "eb825ac4-855b-499b-b37a-b4c37bbd6b4e": {"code": "TUNIS", "name": "Agence Tunis"},
    "1deb1dea-ab54-436d-8ed7-c3322f782c88": {"code": "SOUSSE", "name": "Agence Sousse"},
}

# Correspondances inverses : code lisible -> UUID (ex. "SUV" -> "a6ec349d-...")
CATEGORY_ID_BY_CODE: dict[str, str] = {info["code"]: category_id for category_id, info in CATEGORIES.items()}
AGENCY_ID_BY_CODE: dict[str, str] = {info["code"]: agency_id for agency_id, info in AGENCIES.items()}
