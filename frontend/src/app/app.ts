import { Component } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { currentUsername, hasRole, keycloak, Role, roles } from './core/keycloak';

interface MenuItem {
  label: string;
  path: string;
  roles: Role[];
}

const MENU: MenuItem[] = [
  { label: 'Véhicules', path: '/vehicles', roles: ['CLIENT', 'AGENT', 'ADMIN'] },
  { label: 'Réservations', path: '/reservations', roles: ['CLIENT', 'AGENT', 'ADMIN'] },
  { label: 'Paiements', path: '/payments', roles: ['CLIENT', 'AGENT', 'ADMIN'] },
  { label: 'Contrats et retours', path: '/contracts', roles: ['CLIENT', 'AGENT', 'ADMIN'] },
  { label: 'Réclamations', path: '/complaints', roles: ['CLIENT', 'AGENT', 'ADMIN'] },
  { label: 'Utilisateurs', path: '/users', roles: ['CLIENT', 'AGENT', 'ADMIN'] },
  { label: 'Modèles ML', path: '/ml', roles: ['ADMIN'] },
];

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, RouterLink, RouterLinkActive],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  protected readonly username = currentUsername();
  protected readonly role = roles().find((r) => ['ADMIN', 'AGENT', 'CLIENT'].includes(r)) ?? '';
  protected readonly menu = MENU.filter((item) => hasRole(...item.roles));

  protected logout(): void {
    keycloak.logout({ redirectUri: window.location.origin });
  }
}
