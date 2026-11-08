import { Routes } from '@angular/router';
import { Placeholder } from '../placeholder';

/**
 * Routes du module Modèles ML (Feryel).
 * Remplacer la page provisoire par les composants du module, par exemple :
 *   { path: '', component: MaListe }, { path: ':id', component: MonDetail }
 */
export const ML_ROUTES: Routes = [
  { path: '', component: Placeholder, data: { title: 'Modèles ML', owner: 'Feryel', module: 'ml' } },
];
