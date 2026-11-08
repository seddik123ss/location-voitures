import { Routes } from '@angular/router';
import { roleGuard } from './core/auth';

export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'vehicles' },
  { path: 'vehicles', loadChildren: () => import('./features/vehicles/vehicles.routes').then((m) => m.VEHICLES_ROUTES) },
  { path: 'reservations', canActivate: [roleGuard('CLIENT', 'AGENT', 'ADMIN')], loadChildren: () => import('./features/reservations/reservations.routes').then((m) => m.RESERVATIONS_ROUTES) },
  { path: 'payments', canActivate: [roleGuard('CLIENT', 'AGENT', 'ADMIN')], loadChildren: () => import('./features/payments/payments.routes').then((m) => m.PAYMENTS_ROUTES) },
  { path: 'contracts', canActivate: [roleGuard('CLIENT', 'AGENT', 'ADMIN')], loadChildren: () => import('./features/contracts/contracts.routes').then((m) => m.CONTRACTS_ROUTES) },
  { path: 'complaints', canActivate: [roleGuard('CLIENT', 'AGENT', 'ADMIN')], loadChildren: () => import('./features/complaints/complaints.routes').then((m) => m.COMPLAINTS_ROUTES) },
  { path: 'users', canActivate: [roleGuard('CLIENT', 'AGENT', 'ADMIN')], loadChildren: () => import('./features/users/users.routes').then((m) => m.USERS_ROUTES) },
  { path: 'ml', canActivate: [roleGuard('ADMIN')], loadChildren: () => import('./features/ml/ml.routes').then((m) => m.ML_ROUTES) },
  { path: '**', redirectTo: 'vehicles' },
];
