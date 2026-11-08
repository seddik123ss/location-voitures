import { Routes } from '@angular/router';
import { Placeholder } from '../placeholder';

/**
 * Routes du module Réservations (Aziz).
 * Remplacer la page provisoire par les composants du module, par exemple :
 *   { path: '', component: MaListe }, { path: ':id', component: MonDetail }
 */
export const RESERVATIONS_ROUTES: Routes = [
  { path: '', component: Placeholder, data: { title: 'Réservations', owner: 'Aziz', module: 'reservations' } },
];
