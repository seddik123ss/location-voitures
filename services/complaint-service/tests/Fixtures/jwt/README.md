# Clés de test JWT

`test-key.pem` et `other-key.pem` sont deux clés privées RSA 2048 générées pour les tests de complaint-service.
Elles servent **uniquement aux tests** et n'ont **aucun lien avec Keycloak** : ni le realm `location-voitures`
ni aucun autre environnement ne les connaît, et aucun token qu'elles signent n'est accepté hors des tests.

- `test-key.pem` signe les tokens valides des tests (`tests/Support/TestJwt.php`, kid `test-key`). En environnement de test,
  sa clé publique remplace les clés JWKS du realm (`tests/Support/TestKeySetProvider.php`, branché sous `when@test` dans
  `config/services.yaml`).
- `other-key.pem` signe les tokens à mauvaise signature ou à clé inconnue (`tests/Functional/JwtAuthenticationTest.php`).

Elles sont versionnées pour que les tests passent partout sans étape de génération. Ne les réutilisez jamais ailleurs.
Pour les régénérer : `openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out test-key.pem`.
