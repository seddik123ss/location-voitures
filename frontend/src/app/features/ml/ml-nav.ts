import { Component } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';

/** Onglets affichés en haut des deux pages du module Modèles ML. */
@Component({
  selector: 'app-ml-nav',
  imports: [RouterLink, RouterLinkActive],
  template: `
    <nav class="ml-tabs" aria-label="Pages du module Modèles ML">
      <a routerLink="/ml" routerLinkActive="active" [routerLinkActiveOptions]="{ exact: true }">
        Versions du modèle
      </a>
      <a routerLink="/ml/simulator" routerLinkActive="active">Simulateur de prix</a>
    </nav>
  `,
  styles: `
    .ml-tabs {
      display: flex;
      gap: 4px;
      border-bottom: 1px solid var(--border);
      margin-bottom: 24px;
    }
    a {
      padding: 10px 16px;
      color: var(--muted);
      text-decoration: none;
      border-bottom: 2px solid transparent;
      margin-bottom: -1px;
    }
    a:hover {
      color: var(--primary-dark);
    }
    a.active {
      color: var(--primary);
      border-bottom-color: var(--primary);
      font-weight: 600;
    }
  `,
})
export class MlNav {}
