import { Routes } from '@angular/router';
import { Placeholder } from '../placeholder';

/**
 * Routes du module Utilisateurs (Montassar).
 * Remplacer la page provisoire par les composants du module, par exemple :
 *   { path: '', component: MaListe }, { path: ':id', component: MonDetail }
 */
export const USERS_ROUTES: Routes = [
  { path: '', component: Placeholder, data: { title: 'Utilisateurs', owner: 'Montassar', module: 'users' } },
];
