import { Routes } from '@angular/router';
import { roleGuard } from '../../core/auth';
import { hasRole } from '../../core/keycloak';
import { AccountList } from './account-list';
import { MyPoints } from './my-points';
import { PromoCodeForm } from './promo-code-form';
import { PromoCodeList } from './promo-code-list';

/**
 * Routes du module Fidélité — loyalty-service.
 *   /loyalty                              -> CLIENT : mes points ; AGENT/ADMIN : codes promo
 *   /loyalty/me                           -> mes points (CLIENT)
 *   /loyalty/admin/promo-codes            -> codes promo (ADMIN ; AGENT en lecture)
 *   /loyalty/admin/promo-codes/new        -> création (ADMIN)
 *   /loyalty/admin/promo-codes/:id/edit   -> modification (ADMIN)
 *   /loyalty/admin/accounts               -> comptes clients, ajustement (AGENT, ADMIN)
 */
export const LOYALTY_ROUTES: Routes = [
  { path: '', pathMatch: 'full', redirectTo: () => (hasRole('ADMIN', 'AGENT') ? 'admin/promo-codes' : 'me') },
  { path: 'me', component: MyPoints, canActivate: [roleGuard('CLIENT')], title: 'Mes points' },
  { path: 'admin/promo-codes', component: PromoCodeList, canActivate: [roleGuard('ADMIN', 'AGENT')], title: 'Codes promo' },
  { path: 'admin/promo-codes/new', component: PromoCodeForm, canActivate: [roleGuard('ADMIN')], title: 'Nouveau code promo' },
  { path: 'admin/promo-codes/:id/edit', component: PromoCodeForm, canActivate: [roleGuard('ADMIN')], title: 'Modifier le code promo' },
  { path: 'admin/accounts', component: AccountList, canActivate: [roleGuard('ADMIN', 'AGENT')], title: 'Comptes de fidélité' },
];
