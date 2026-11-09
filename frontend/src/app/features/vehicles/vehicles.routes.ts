import { Routes } from '@angular/router';
import { roleGuard } from '../../core/auth';
import { VehicleDetail } from './vehicle-detail';
import { VehicleForm } from './vehicle-form';
import { VehicleList } from './vehicle-list';

/**
 * Routes du module Véhicules (Yosra) — page d'accueil de l'application.
 * - ''        : catalogue avec filtres (tous les rôles)
 * - new       : ajout d'un véhicule (AGENT, ADMIN)
 * - :id       : détail ; AGENT / ADMIN : modifier, supprimer, maintenances
 * - :id/edit  : modification (AGENT, ADMIN)
 */
export const VEHICLES_ROUTES: Routes = [
  { path: '', component: VehicleList, title: 'Véhicules' },
  { path: 'new', component: VehicleForm, canActivate: [roleGuard('AGENT', 'ADMIN')], title: 'Nouveau véhicule' },
  { path: ':id', component: VehicleDetail, title: 'Véhicule' },
  { path: ':id/edit', component: VehicleForm, canActivate: [roleGuard('AGENT', 'ADMIN')], title: 'Modifier le véhicule' },
];