# ml-service — Tarification dynamique

Service FastAPI (port 8107, nom Eureka **RENTAL-ML**) qui propose un **prix journalier conseillé**
pour une location de voiture, calculé par un modèle XGBoost versionné.

## Lancer

```powershell
# 1. Infrastructure (dans infra/)
docker compose up -d postgres rabbitmq keycloak eureka gateway

# 2. Service (dans services/ml-service/)
py -3.12 -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
copy .env.example .env          # KEYCLOAK_CLIENT_SECRET : seulement pour obtenir un token de service (tests INTERNAL)
alembic upgrade head            # tables + version 1 du modèle, active
uvicorn main:app --host 0.0.0.0 --port 8107

# 3. Front (dans frontend/) : npm ci la première fois, puis npm start ; connexion avec admin1 / admin1_pwd

# 4. Tests (une seule fois : créer la base de test)
docker exec location-voitures-postgres-1 psql -U postgres -c "CREATE DATABASE ml_test_db OWNER ml_svc;"
pytest -v                       # 29 tests, ~7 s, sur ml_test_db (jamais ml_db) ; venv activé, PostgreSQL démarré
```

Documentation interactive : http://localhost:8107/docs — contrat : `docs/openapi/ml-service.yaml`.

## Endpoints

| Route | Rôle | Description |
|---|---|---|
| `POST /api/ml/price-predictions` | ADMIN | Prix journalier conseillé et intervalle |
| `GET /api/ml/models`, `GET /api/ml/models/{id}` | ADMIN | Liste et détail des versions |
| `PUT` / `DELETE /api/ml/models/{id}` | ADMIN | Renommer / supprimer une version inactive |
| `POST /api/ml/models/{id}/activate` | ADMIN | Activer une version |
| `POST /api/ml/trainings` | ADMIN | Réentraîner (nouvelle version, inactive) |
| `POST /internal/ml/price-predictions` | INTERNAL | Même prédiction, appelée par reservation-service |
| `GET /health` | public | État du service |

Règles d'erreur : **422** avec toutes les erreurs dans `details` (dates incohérentes, départ passé ou à plus
de 180 jours, durée de plus de 30 jours, catégorie ou agence inconnue), **409** si on supprime la version active,
**503** sans modèle actif, **401/403** sans token ou sans le bon rôle.

**Versions** : le numéro vient d'une séquence PostgreSQL et n'est jamais réutilisé. Seul
`artifacts/price_model_v1.joblib` est versionné dans Git ; les versions suivantes restent locales.

## Le modèle

**Ce qu'il fait** : il propose un **prix journalier conseillé** (en TND) pour une location,
avec un **intervalle** dans lequel le vrai prix tombe 9 fois sur 10.

**Ce qu'il reçoit** : la catégorie du véhicule, l'agence et les dates de départ et de retour.
Le service en déduit la saison, le jour de départ, la durée et le délai de réservation.
Le taux d'occupation est estimé (moyenne par agence et par saison) s'il n'est pas fourni.

**Les données** : 10 000 réservations synthétiques (2023-2025), générées par
`scripts/generate_rental_dataset.py` à partir des tarifs de l'équipe et de règles réalistes,
avec un bruit de ±5 %. Les réservations terminées reçues par RabbitMQ s'y ajoutent à chaque réentraînement.

| Modèle (validation) | MAE | R² |
|---|---|---|
| **XGBoost (retenu)** | **6,9 TND** | **0,988** |
| Random Forest | 8,3 TND | 0,982 |
| Régression linéaire | 16,5 TND | 0,924 |

Sur le test (jamais utilisé avant) : **MAE 6,7 TND**, **R² 0,988**, **90,8 %** des prix dans l'intervalle.
Détails : `notebooks/price_model_training.ipynb`.

## Intégration

- **Eureka** : inscription au démarrage sous RENTAL-ML (`host.docker.internal:8107`).
- **Keycloak** : vérification des tokens (signature, expiration, émetteur, rôles).
- **RabbitMQ** : consommation de `ml.reservation_completed` ; message invalide → `ml.reservation_completed.dlq`.
  Pour tester : console http://localhost:15672 (`guest` / `guest`) → file `ml.reservation_completed` →
  « Publish message » (`content_type` = `application/json`, delivery mode 2, JSON de `docs/events/ReservationCompleted.json`
  avec un nouvel `eventId`).

## Limites

Données d'entraînement synthétiques, occupation estimée, saison calculée seulement à partir du mois
(ramadan et Aïd non pris en compte), audience du token non vérifiée.
L'intervalle utilise le même pourcentage pour tous les cas, et le modèle n'est entraîné que sur 60 % des données.
Si RabbitMQ est éteint au démarrage du service, le consommateur ne démarre pas : relancer le service
une fois RabbitMQ disponible.

## Usage de l'IA

J'ai utilisé Claude tout au long du développement.
