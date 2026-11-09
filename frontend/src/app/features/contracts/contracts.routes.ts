import { Routes } from '@angular/router';
import { roleGuard } from '../../core/auth';
import { ContractDetail } from './contract-detail';
import { ContractList } from './contract-list';
import { HandoverForm } from './handover-form';

/**
 * Routes du module Contrats et retours (Rania).
 * - liste : "Mes contrats" (CLIENT) ou tous les contrats (AGENT, ADMIN)
 * - new : remise des clés (AGENT, ADMIN)
 * - :id : détail, états des lieux, frais, retour du véhicule (AGENT, ADMIN)
 */
export const CONTRACTS_ROUTES: Routes = [
  { path: '', component: ContractList },
  { path: 'new', component: HandoverForm, canActivate: [roleGuard('AGENT', 'ADMIN')] },
  { path: ':id', component: ContractDetail },
];
