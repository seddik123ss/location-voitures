import { Routes } from '@angular/router';
import { Placeholder } from '../placeholder';

/**
 * Routes du module Véhicules (Yosra).
 * Remplacer la page provisoire par les composants du module, par exemple :
 *   { path: '', component: MaListe }, { path: ':id', component: MonDetail }
 */
export const VEHICLES_ROUTES: Routes = [
  { path: '', component: Placeholder, data: { title: 'Véhicules', owner: 'Yosra', module: 'vehicles' } },
];
