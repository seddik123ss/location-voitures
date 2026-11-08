# Frontend Angular (rental-front)

Angular 21 (compatible Node 20.19+, 22.12+ et 24), composants standalone.

## Lancer

1. L'infrastructure doit tourner (`docker compose up -d` dans `infra/`), Keycloak compris.
2. `npm install` (la première fois).
3. `npm start` puis ouvrir http://localhost:4200 : redirection vers Keycloak, se connecter avec `client1` / `client1_pwd` (ou `agent1`, `admin1`).

Le proxy de développement (`proxy.conf.json`) envoie tous les appels `/api/...` vers la Gateway (http://localhost:8088). Dans le code, appeler toujours `/api/...`, jamais l'adresse d'un service.

## Ce qui est déjà en place

- `src/app/core/keycloak.ts` : connexion Keycloak (PKCE, client `rental-front`), `hasRole()`, `currentUserId()` (= `sub`, l'UUID de l'utilisateur).
- `src/app/core/auth.ts` : intercepteur qui ajoute le token aux appels `/api`, et `roleGuard(...)` pour les routes.
- `src/app/core/api.ts` : types `Page<T>` et `ApiError` des conventions, `errorMessage()`.
- Layout (barre latérale filtrée par rôle, en-tête, déconnexion).

## Où coder son module

Chaque membre travaille uniquement dans son dossier `src/app/features/<module>/` et remplace la page provisoire dans `<module>.routes.ts` :

| Dossier | Membre |
| --- | --- |
| users | Montassar |
| vehicles | Yosra |
| reservations | Aziz |
| payments | Fadi |
| contracts | Rania |
| complaints | Seddik |
| ml | Feryel |

Générer un composant : `npx ng generate component features/vehicles/vehicle-list`.
