import { Routes } from '@angular/router';
import { Placeholder } from '../placeholder';

/**
 * Routes du module Paiements (Fadi).
 * Remplacer la page provisoire par les composants du module, par exemple :
 *   { path: '', component: MaListe }, { path: ':id', component: MonDetail }
 */
export const PAYMENTS_ROUTES: Routes = [
  { path: '', component: Placeholder, data: { title: 'Paiements', owner: 'Fadi', module: 'payments' } },
];
