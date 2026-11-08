import { HttpInterceptorFn } from '@angular/common/http';
import { CanActivateFn } from '@angular/router';
import { from, switchMap } from 'rxjs';
import { hasRole, keycloak, Role } from './keycloak';

/** Ajoute le token Keycloak (rafraîchi si besoin) à chaque appel /api. */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  if (!req.url.startsWith('/api')) {
    return next(req);
  }
  return from(keycloak.updateToken(30)).pipe(
    switchMap(() => next(req.clone({ setHeaders: { Authorization: `Bearer ${keycloak.token}` } }))),
  );
};

/** Garde de route : laisse passer si l'utilisateur a au moins un des rôles. */
export const roleGuard = (...wanted: Role[]): CanActivateFn => () => hasRole(...wanted);
