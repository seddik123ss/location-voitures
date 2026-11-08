import { Routes } from '@angular/router';
import { Placeholder } from '../placeholder';

/**
 * Routes du module Réclamations (Seddik).
 * Remplacer la page provisoire par les composants du module, par exemple :
 *   { path: '', component: MaListe }, { path: ':id', component: MonDetail }
 */
export const COMPLAINTS_ROUTES: Routes = [
  { path: '', component: Placeholder, data: { title: 'Réclamations', owner: 'Seddik', module: 'complaints' } },
];
