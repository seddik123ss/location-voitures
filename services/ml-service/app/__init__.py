"""Code applicatif du ml-service (tarification dynamique).

Un module = un rôle :
- settings.py          : configuration lue depuis le .env
- rental_catalog.py    : catégories et agences connues (liste provisoire)
- price_features.py    : calcul des variables du modèle à partir d'une réservation
- model_training.py    : briques d'entraînement + enchaînement complet (POST /trainings)
- model_artifacts.py   : sauvegarde et chargement des modèles (artifacts/price_model_v<N>.joblib)
- database.py          : connexion PostgreSQL (SQLAlchemy)
- db_tables.py         : tables model_versions et prediction_logs
- api_schemas.py       : formes JSON d'entrée/sortie (camelCase)
- api_docs.py          : textes de Swagger (descriptions, exemples, réponses d'erreur)
- error_handlers.py    : exceptions métier et format d'erreur commun
- price_predictor.py   : prédiction (règles 422/503, occupation estimée, intervalle, log)
- model_registry.py    : gestion des versions (liste, renommage, suppression, activation)
- public_routes.py     : routes /api/ml
- internal_routes.py   : routes /internal/ml

- eureka_client.py     : inscription dans Eureka (RENTAL-ML)
- keycloak_security.py : vérification des tokens Keycloak et des rôles
- completed_reservations.py : réservations terminées -> lignes d'entraînement
- rabbitmq_consumer.py : consommateur de la file ml.reservation_completed
"""
