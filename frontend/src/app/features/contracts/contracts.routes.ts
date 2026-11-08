import { Routes } from '@angular/router';
import { Placeholder } from '../placeholder';

/**
 * Routes du module Contrats et retours (Rania).
 * Remplacer la page provisoire par les composants du module, par exemple :
 *   { path: '', component: MaListe }, { path: ':id', component: MonDetail }
 */
export const CONTRACTS_ROUTES: Routes = [
  { path: '', component: Placeholder, data: { title: 'Contrats et retours', owner: 'Rania', module: 'contracts' } },
];
