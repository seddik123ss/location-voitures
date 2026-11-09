import { registerLocaleData } from '@angular/common';
import localeFr from '@angular/common/locales/fr';
import { inject } from '@angular/core';
import { CanActivateFn, Router, Routes } from '@angular/router';
import { hasRole } from '../../core/keycloak';
import { ContractDetail } from './contract-detail';
import { ContractList } from './contract-list';
import { HandoverForm } from './handover-form';

// Format français des nombres dans ce module (28 750 km, 90,000 TND, 50 %)
registerLocaleData(localeFr);

/** Remise des clés réservée à AGENT / ADMIN ; un CLIENT est renvoyé vers sa liste au lieu d'une page vide. */
const staffOnly: CanActivateFn = () =>
  hasRole('AGENT', 'ADMIN') || inject(Router).createUrlTree(['/contracts']);

/**
 * Routes du module Contrats et retours (Rania).
 * - liste : "Mes contrats" (CLIENT) ou tous les contrats (AGENT, ADMIN)
 * - new : remise des clés (AGENT, ADMIN)
 * - :id : détail, états des lieux, frais, retour du véhicule (AGENT, ADMIN)
 */
export const CONTRACTS_ROUTES: Routes = [
  { path: '', component: ContractList },
  { path: 'new', component: HandoverForm, canActivate: [staffOnly] },
  { path: ':id', component: ContractDetail },
];
