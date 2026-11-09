import { Routes } from '@angular/router';
import { ModelsDashboard } from './models-dashboard';
import { PriceSimulator } from './price-simulator';

/**
 * Routes du module Modèles ML (Feryel). L'accès à /ml est réservé aux ADMIN (app.routes.ts).
 *   /ml            -> tableau de bord des versions du modèle
 *   /ml/simulator  -> simulateur de prix
 */
export const ML_ROUTES: Routes = [
  { path: '', component: ModelsDashboard, title: 'Modèles ML · Versions' },
  { path: 'simulator', component: PriceSimulator, title: 'Modèles ML · Simulateur de prix' },
];
