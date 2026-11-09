# Clés de test JWT

`test-key.pem` et `other-key.pem` sont deux clés privées RSA 2048 générées pour les tests de reservation-service.
Elles servent **uniquement aux tests** et n'ont **aucun lien avec Keycloak** : aucun token qu'elles signent n'est accepté
hors des tests, car le service ne fait confiance qu'aux clés publiées par le realm `location-voitures` (JWKS).

- `test-key.pem` signe les tokens valides des tests (`tests/Support/TestJwt.php`, kid `test-key`). En test, sa clé publique
  remplace le JWKS du realm (`tests/Support/TestKeySetProvider.php`, branché dans `tests/TestCase.php`).
- `other-key.pem` signe des tokens à mauvaise signature.

Elles sont versionnées pour que les tests passent partout sans étape de génération. Ne les réutilisez jamais ailleurs.
Pour les régénérer : `openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out test-key.pem`.
