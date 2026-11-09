import { Routes } from '@angular/router';
import { roleGuard } from '../../core/auth';
import { hasRole } from '../../core/keycloak';
import { MyProfile } from './my-profile';
import { UserDetail } from './user-detail';
import { UserForm } from './user-form';
import { UserList } from './user-list';

/**
 * Routes du module Utilisateurs (Montassar) — user-service.
 *   /users             -> ADMIN/AGENT : liste ; CLIENT : mon profil
 *   /users/me          -> mon profil (tout utilisateur connecté)
 *   /users/admin       -> liste filtrée et paginée (ADMIN, AGENT)
 *   /users/admin/new   -> création (ADMIN)
 *   /users/admin/:id   -> fiche, vérification du permis (ADMIN, AGENT)
 *   /users/admin/:id/edit -> modification (ADMIN)
 */
export const USERS_ROUTES: Routes = [
  { path: '', pathMatch: 'full', redirectTo: () => (hasRole('ADMIN', 'AGENT') ? 'admin' : 'me') },
  { path: 'me', component: MyProfile, title: 'Mon profil' },
  { path: 'admin', component: UserList, canActivate: [roleGuard('ADMIN', 'AGENT')], title: 'Utilisateurs' },
  { path: 'admin/new', component: UserForm, canActivate: [roleGuard('ADMIN')], title: 'Nouvel utilisateur' },
  { path: 'admin/:id', component: UserDetail, canActivate: [roleGuard('ADMIN', 'AGENT')], title: 'Utilisateur' },
  { path: 'admin/:id/edit', component: UserForm, canActivate: [roleGuard('ADMIN')], title: "Modifier l'utilisateur" },
];
