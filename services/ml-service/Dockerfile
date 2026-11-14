# Image de ml-service (FastAPI, Python 3.12 comme en local).
# Lancement normal : infra/docker-compose.yml, profil "services" (variables d'environnement fournies par compose).
# Un seul processus : API + inscription Eureka (RENTAL-ML) + consommateur RabbitMQ (ml.reservation_completed).
# Au démarrage : alembic upgrade head (nouvel essai tant que PostgreSQL ne répond pas), puis uvicorn.
FROM python:3.12-slim

# libgomp1 : OpenMP, requis par xgboost et scikit-learn
RUN apt-get update && apt-get install -y --no-install-recommends libgomp1 && rm -rf /var/lib/apt/lists/*
ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    SERVICE_PORT=8107
WORKDIR /app

# Dépendances d'exécution seulement : sans Jupyter ni les graphiques du notebook, ni pytest.
COPY requirements.txt .
RUN grep -vE '^(jupyter|matplotlib|seaborn|pytest)==' requirements.txt > requirements-docker.txt \
    && pip install --no-cache-dir -r requirements-docker.txt

COPY main.py alembic.ini ./
COPY alembic ./alembic
COPY app ./app
# Jeu de données (réentraînement) et modèle de démonstration v1 ; compose monte artifacts/ du dépôt
# pour que les versions entraînées restent visibles en local comme dans le conteneur.
COPY data ./data
COPY artifacts ./artifacts

EXPOSE 8107
# sh -c : alembic puis exec uvicorn (qui reçoit SIGTERM au docker stop et se retire d'Eureka).
CMD ["sh", "-c", "for i in $(seq 1 20); do alembic upgrade head && break; echo 'PostgreSQL pas encore prêt, nouvel essai dans 3 s' >&2; sleep 3; done && exec uvicorn main:app --host 0.0.0.0 --port ${SERVICE_PORT}"]
