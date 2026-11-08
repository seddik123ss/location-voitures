import { bootstrapApplication } from '@angular/platform-browser';
import { appConfig } from './app/app.config';
import { App } from './app/app';
import { keycloak } from './app/core/keycloak';

// Connexion Keycloak (PKCE) avant de démarrer Angular : l'application ne s'affiche qu'à un utilisateur connecté.
keycloak
  .init({ onLoad: 'login-required', pkceMethod: 'S256', checkLoginIframe: false })
  .then(() => bootstrapApplication(App, appConfig))
  .catch((err) => {
    console.error('Connexion Keycloak impossible', err);
    document.body.innerHTML =
      '<p style="font-family:sans-serif;padding:32px">Keycloak est injoignable sur http://localhost:8180. Lancez <code>docker compose up -d keycloak</code> dans infra/ puis rechargez la page.</p>';
  });
