import { Routes } from '@angular/router';
import { roleGuard } from '../../core/auth';
import { ComplaintDetail } from './complaint-detail';
import { ComplaintForm } from './complaint-form';
import { ComplaintList } from './complaint-list';

/**
 * Routes du module Réclamations (Seddik). L'accès au module (CLIENT, AGENT, ADMIN) est contrôlé dans app.routes.ts.
 * - ''         : CLIENT « Mes réclamations » ; AGENT / ADMIN toutes les réclamations
 * - new        : nouvelle réclamation (CLIENT)
 * - :id        : détail, messages, historique ; actions selon le rôle et le statut
 * - :id/edit   : modification par le client auteur tant que la réclamation est OPEN
 */
export const COMPLAINTS_ROUTES: Routes = [
  { path: '', component: ComplaintList, title: 'Réclamations' },
  { path: 'new', component: ComplaintForm, canActivate: [roleGuard('CLIENT')], title: 'Nouvelle réclamation' },
  { path: ':id', component: ComplaintDetail, title: 'Réclamation' },
  { path: ':id/edit', component: ComplaintForm, canActivate: [roleGuard('CLIENT')], title: 'Modifier la réclamation' },
];
