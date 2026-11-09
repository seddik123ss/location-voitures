"""Code applicatif du ml-service (tarification dynamique).

Un module = un rôle :
- settings.py          : configuration lue depuis le .env
- rental_catalog.py    : catégories et agences connues (liste provisoire)
- price_features.py    : calcul des variables du modèle à partir d'une réservation

À venir : database.py, db_tables.py, api_schemas.py, error_handlers.py,
model_training.py, price_predictor.py, public_routes.py (/api/ml),
internal_routes.py (/internal/ml), puis eureka_client.py,
keycloak_security.py, rabbitmq_consumer.py.
"""
