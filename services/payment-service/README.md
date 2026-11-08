# payment-service

Paiements et factures de la plateforme de location de voitures.
Responsable : **Fadi** · Django 5.2 + Django REST Framework · Python 3.12 · PostgreSQL `payment_db` · port **8104** · Eureka **RENTAL-PAYMENT**.

| | |
| --- | --- |
| Routes publiques (via la Gateway 8088) | `/api/payments/**`, `/api/invoices/**` |
| Route interne (jamais exposée) | `GET /internal/payments?reservationId=` (contract-service) |
| Appel sortant | RENTAL-RESERVATION `GET /internal/reservations/{id}` |
| RabbitMQ | publie `reservation.payment_succeeded` ; consomme `payment.reservation_cancelled`, `payment.return_settled` |
| Swagger UI | http://127.0.0.1:8104/swagger · contrat partagé : [`docs/openapi/payment-service.yaml`](../../docs/openapi/payment-service.yaml) |
| Santé | `GET /health` → `{"status": "UP"}` |

---

## 1. Démarrage (Windows, PowerShell)

Prérequis : Docker Desktop démarré, Python 3.12 (`py -3.12`), infrastructure lancée depuis `infra/` :

```powershell
cd infra
copy .env.example .env          # une seule fois
docker compose up -d mysql postgres rabbitmq keycloak
```

La base `payment_db` et l'utilisateur `payment_svc` sont créés par `infra/postgres/init.sql`.

```powershell
cd services\payment-service
py -3.12 -m venv venv
.\venv\Scripts\Activate.ps1     # si bloqué : Set-ExecutionPolicy -Scope CurrentUser RemoteSigned
pip install -r requirements.txt
copy .env.example .env          # puis compléter les secrets (jamais commité)
python manage.py migrate
python manage.py seed_demo      # paiements de démo de docs/demo-ids.md
python manage.py runserver 8104
```

### Variables d'environnement (`.env`, modèle : `.env.example`)

| Variable | Rôle | Valeur locale |
| --- | --- | --- |
| `SERVICE_PORT` | port du service | `8104` |
| `DB_URL` | connexion PostgreSQL | `postgres://payment_svc:payment_pwd@localhost:5432/payment_db` |
| `EUREKA_URL` | annuaire Eureka | `http://localhost:8761/eureka` |
| `KEYCLOAK_ISSUER` | émetteur des tokens | `http://localhost:8180/realms/location-voitures` |
| `KEYCLOAK_CLIENT_ID` / `KEYCLOAK_CLIENT_SECRET` | compte de service (appels `/internal`) | `payment-service` / secret du realm |
| `RABBITMQ_URL` | courtier de messages | `amqp://guest:guest@localhost:5672/%2F` |
| `AUTH_MODE` | `dev` (en-têtes, local uniquement) ou `jwt` (Keycloak) | `dev` jusqu'à l'étape 3 |
| `EVENT_PUBLISHER` | `log` (journalisé) ou `rabbitmq` | `log` jusqu'à l'étape 3 |
| `RESERVATION_CLIENT` | `fake` (simulé) ou `http` (Eureka) | `fake` tant que reservation-service n'est pas prêt |

### Tests

```powershell
python manage.py test payments
```

64 tests, sur une vraie base PostgreSQL temporaire (`test_payment_db`, créée puis détruite par Django ;
`payment_svc` doit avoir le droit `CREATEDB`, voir « Dépannage »). Couverture : règles métier et codes HTTP,
idempotence, rollback quand RabbitMQ est indisponible, concurrence (deux paiements simultanés),
traduction des erreurs inter-services, traitement des messages officiels de `docs/events/`.

### Régénérer le contrat OpenAPI partagé

```powershell
$env:OPENAPI_FOR_DOCS = "1"
python manage.py spectacular --validate --file ..\..\docs\openapi\payment-service.yaml
Remove-Item Env:OPENAPI_FOR_DOCS
```

---

## 2. Modèle de données

Pas de clé étrangère vers les autres bases : `reservationId`, `userId` sont de simples UUID.

| Table | Contenu |
| --- | --- |
| `Payment` | `reservationId`, `userId`, `amount` (décimal 12,3), `currency` (TND), `method` (CARD, CASH, TRANSFER ; vide tant qu'un frais est PENDING), `type` (RENTAL, DEPOSIT, EXTRA_FEE, REFUND), `status` (PENDING, SUCCEEDED, FAILED, REFUNDED), `transactionRef`, `idempotencyKey` (unique), `originalPayment` (pour un REFUND), `feeType` (LATE, FUEL, MILEAGE, DAMAGE pour un EXTRA_FEE), `failureReason` |
| `Invoice` / `InvoiceLine` | facture `INV-AAAA-NNNNNN` (unique), réservation, client, total, date, lignes |
| `InvoiceCounter` | compteur par année, verrouillé (`SELECT … FOR UPDATE`) : numéros sans doublon ni trou |
| `ProcessedEvent` | `eventId` des messages RabbitMQ déjà traités (idempotence du consommateur) |

Contraintes vérifiées par PostgreSQL (dernière ligne de défense, même en cas de requêtes simultanées) :
montant > 0 ; devise TND ; **un seul RENTAL et un seul DEPOSIT SUCCEEDED par réservation** ; un seul
remboursement par paiement ; un REFUND référence toujours son paiement d'origine ; moyen de paiement
obligatoire dès que de l'argent a circulé (SUCCEEDED, REFUNDED) ; `feeType` seulement pour EXTRA_FEE.

---

## 3. API et règles métier

| Méthode et route | Rôles | Effet |
| --- | --- | --- |
| `GET /api/payments` | CLIENT (les siens), AGENT, ADMIN | liste paginée ; filtres `reservationId`, `userId`, `status`, `type` |
| `POST /api/payments` | CLIENT, AGENT, ADMIN | payer la location (RENTAL) ou la caution (DEPOSIT) ; `Idempotency-Key` obligatoire |
| `GET /api/payments/{id}` | propriétaire, AGENT, ADMIN | détail |
| `PATCH /api/payments/{id}` | ADMIN | uniquement un paiement PENDING (ex. valider un virement) |
| `DELETE /api/payments/{id}` | ADMIN | uniquement PENDING ou FAILED, et absent de toute facture |
| `POST /api/payments/{id}/pay` | propriétaire (carte), AGENT/ADMIN (carte, espèces) | régler un paiement PENDING (frais de retour) ; `Idempotency-Key` obligatoire |
| `GET /api/invoices`, `GET /api/invoices/{id}` | CLIENT (les siennes), AGENT, ADMIN | lecture seule : les factures sont générées, jamais saisies |
| `GET /internal/payments?reservationId=` | INTERNAL | liste simple (non paginée), contrat de `docs/internal-api.md` |

**Règles de `POST /api/payments`** (dans cet ordre, la première qui échoue l'emporte) :

| Situation | Code |
| --- | --- |
| corps invalide (UUID, type, plus de 3 décimales…) ou `Idempotency-Key` absent | 400 |
| `Idempotency-Key` déjà utilisée (**même pour une requête identique**) | 409 |
| CLIENT qui paie en espèces | 403 |
| réservation inexistante / reservation-service arrêté / réponse illisible | 404 / 503 / 502 |
| CLIENT qui paie la réservation d'un autre | 403 |
| réservation CANCELLED ou COMPLETED | 409 |
| déjà un paiement SUCCEEDED (ou PENDING) du même type | 409 |
| montant ≠ `totalPrice` (RENTAL) ou ≠ `depositAmount` (DEPOSIT) | 422 (le montant attendu est dans `details`) |
| carte refusée (`cardToken` commençant par `fail_`) | **201**, paiement enregistré FAILED |
| succès | 201 + en-tête `Location` |

Moyens de paiement (simulés, **aucune donnée de carte n'est stockée**, le `cardToken` n'est lu qu'en mémoire) :
CARD → SUCCEEDED ou FAILED immédiatement ; CASH → SUCCEEDED (agent à l'agence) ; TRANSFER → PENDING jusqu'à validation par un ADMIN.
Un paiement RENTAL réussi génère une facture.

---

## 4. Points clés à défendre

### Idempotence côté API (`Idempotency-Key`)
Le client génère une clé (UUID) par intention de paiement et la renvoie s'il réessaie (timeout, double clic).
La clé est stockée dans une colonne **unique** : une clé déjà enregistrée renvoie **409**, même si la requête
est identique (choix de l'équipe : on ne rejoue pas la réponse d'origine). La vérification est faite deux fois :
lecture avant traitement, puis contrainte d'unicité en base si deux requêtes arrivent en même temps
(`IntegrityError` traduite en 409). Si la transaction est annulée (ex. RabbitMQ indisponible), la clé n'est
**pas** consommée et le client peut réessayer avec la même clé.

### Transactions et publication RabbitMQ
`payments/services.py`, schéma suivi par chaque cas d'usage :
1. contrôles et appel HTTP à reservation-service **hors transaction** (aucun verrou pendant un appel réseau) ;
2. `transaction.atomic()` : écriture du paiement, de la facture, puis **publication de `PaymentSucceeded` en dernier** ;
3. si la publication échoue, l'exception `EventPublishError` (503) annule toute la transaction : pas de paiement
   enregistré sans message, et le client reçoit 503.

Limite connue : si la base échoue au `COMMIT` juste après une publication réussie, un message peut partir sans
paiement. Le motif « outbox » (table d'événements à publier par un processus séparé) supprimerait ce cas ;
la publication dans la transaction a été retenue car c'est la règle de l'équipe (`docs/events/README.md`).

### Idempotence côté consommateur (`eventId`)
Chaque message traité ajoute son `eventId` dans `ProcessedEvent` **dans la même transaction** que les effets
métier. Un message relivré après validation est ignoré ; un crash avant validation ne laisse aucune trace et le
message relivré est traité normalement. Un message invalide (`InvalidEvent`) annule tout et part en `.dlq`.

- `ReservationCancelled` : un REFUND par paiement SUCCEEDED de la réservation (l'original passe REFUNDED) ; les paiements PENDING passent FAILED.
- `ReturnSettled` : un EXTRA_FEE PENDING par frais + une facture des frais ; REFUND de `depositToRelease`
  (la caution passe REFUNDED). Si aucune caution n'a été payée via ce service (cas des données de démo), un
  avertissement est journalisé et seuls les frais sont traités, pour ne pas envoyer en `.dlq` un message valide.

### Traduction des erreurs inter-services
`payments/clients/reservations.py` : interface `ReservationClient`, deux implémentations choisies par
`RESERVATION_CLIENT` (simulée / HTTP via Eureka). Réponse 404 distante → **404** ; service absent d'Eureka,
connexion refusée, timeout, 503 distant → **503** ; JSON invalide, champ manquant, statut inconnu, autre code → **502**.
Le client simulé reprend les réservations de `docs/demo-ids.md` ; les UUID `ffffffff-ffff-4fff-8fff-000000000503`
et `…000000000502` simulent une panne et une réponse illisible (utile en démonstration).

### Concurrence
Deux paiements simultanés pour la même réservation passent tous deux le contrôle « déjà payé ? ». L'index unique
partiel PostgreSQL n'en laisse passer qu'un ; le second reçoit 409 (test `test_concurrency.py`).

### Authentification (étape actuelle)
`AUTH_MODE=dev` : identité lue dans les en-têtes `X-User-Id` et `X-User-Roles` (**développement local
uniquement**). À l'étape 3 : JWT Keycloak (PyJWT + PyJWKClient), `sub` = utilisateur, claim `roles` = rôles,
sans modification des vues. Toutes les vues sont fermées par défaut (`IsAuthenticated`) et déclarent leurs rôles.

### Formats communs
Erreurs `{status, error, message, details[{field, message}]}` pour **toutes** les erreurs (validation, 401, 403,
404, 405, métier, 500) ; pagination `?page=0&size=20` → `{items, page, size, total}` ; JSON camelCase ;
`X-Correlation-Id` relu, renvoyé et retransmis aux autres services.

---

## 5. Données de démonstration (`docs/demo-ids.md`)

`python manage.py seed_demo` (idempotent ; `--reset` efface **toutes** les données du service) :

| Paiement | Réservation | Client | Type | Montant | Statut |
| --- | --- | --- | --- | --- | --- |
| p-confirmed `d76b538e-…` | r-confirmed | client1 | RENTAL | 450.000 | SUCCEEDED (+ facture) |
| p-completed `918e07dc-…` | r-completed | client2 | RENTAL | 640.000 | SUCCEEDED (+ facture) |

Exemple (mode dev) : client1 paie la location de r-pending (270.000 TND).

```powershell
$h = @{ "X-User-Id"="f4d1bab3-468d-428d-b02c-b44341430bfd"; "X-User-Roles"="CLIENT"; "Idempotency-Key"=[guid]::NewGuid().ToString() }
$body = '{"reservationId":"01cb68ad-4eec-4bdd-8971-ce35381c59d1","type":"RENTAL","method":"CARD","amount":270.0,"cardToken":"tok_visa"}'
Invoke-RestMethod -Method Post http://127.0.0.1:8104/api/payments -Headers $h -ContentType "application/json" -Body $body
```

---

## 6. Dépannage

- **`role "payment_svc" does not exist`** : un PostgreSQL installé sur Windows occupe déjà le port 5432.
  PowerShell administrateur : `Stop-Service postgresql-x64-18` (et `Set-Service postgresql-x64-18 -StartupType Manual`).
- **Tests : `permission denied to create database`** :
  `docker compose exec postgres psql -U postgres -c "ALTER ROLE payment_svc CREATEDB;"` (depuis `infra/`).
- **Requêtes lentes (~2 s) vers `localhost` depuis Python** : utiliser `127.0.0.1` (résolution IPv6 puis IPv4 sous Windows).
- **`Activate.ps1` bloqué** : `Set-ExecutionPolicy -Scope CurrentUser RemoteSigned`.

---

## 7. Usage de l'IA

Un assistant de programmation IA a été utilisé dans VS Code, sous ma direction, par petites étapes validées une à une.

**Généré par l'IA** (à partir de mes consignes et des documents de l'équipe) :
- squelette Django/DRF et configuration (`config/settings.py`) ;
- modèles, contraintes et migrations ; numérotation des factures ;
- couche métier `services.py`, sérialiseurs, vues, documentation Swagger ;
- format d'erreur commun, pagination, `X-Correlation-Id`, client reservation-service simulé/HTTP ;
- tests automatisés et ce README ;
- diagnostic de l'environnement (conflit de port avec un PostgreSQL local).

**Décidé, vérifié ou modifié par moi** :
- règles métier et codes HTTP (montant attendu, 409 sur clé déjà utilisée, carte `fail_`, rôles, CRUD réservé à ADMIN) ;
- contrats inter-services et données de démo alignés sur `docs/` (en lien avec l'équipe) ;
- choix proposés puis validés : virement PENDING validé par ADMIN, remboursement de tous les paiements SUCCEEDED à l'annulation, `ReturnSettled` sans caution payée traité sans rejet ;
- exécution et lecture des tests, des migrations et des appels de l'API à chaque étape ;
- à compléter : _relecture personnelle des fichiers clés (`services.py`, `models.py`, `exceptions.py`, `clients/reservations.py`) et modifications faites à la main_.
