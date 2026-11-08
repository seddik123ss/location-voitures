import Keycloak from 'keycloak-js';

/** Client Keycloak partagé par toute l'application (realm location-voitures, client public rental-front). */
export const keycloak = new Keycloak({
  url: 'http://localhost:8180',
  realm: 'location-voitures',
  clientId: 'rental-front',
});

export type Role = 'CLIENT' | 'AGENT' | 'ADMIN';

/** Rôles du realm, lus dans la claim "roles" du token (mapper roles-flat). */
export function roles(): string[] {
  return ((keycloak.tokenParsed as { roles?: string[] } | undefined)?.roles) ?? [];
}

export function hasRole(...wanted: Role[]): boolean {
  const mine = roles();
  return wanted.some((r) => mine.includes(r));
}

export function currentUserId(): string | undefined {
  return keycloak.tokenParsed?.sub;
}

export function currentUsername(): string {
  return (keycloak.tokenParsed as { preferred_username?: string } | undefined)?.preferred_username ?? '';
}
