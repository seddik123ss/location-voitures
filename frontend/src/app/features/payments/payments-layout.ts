import { Component, ViewEncapsulation } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';

import { hasRole } from '../../core/keycloak';

/** Frame of the Payments module: tabs + styles shared by its pages (scoped under .payments). */
@Component({
  selector: 'app-payments-layout',
  imports: [RouterOutlet, RouterLink, RouterLinkActive],
  encapsulation: ViewEncapsulation.None,
  template: `
    <section class="payments">
      <h1>Paiements</h1>
      <nav class="tabs">
        <a routerLink="." routerLinkActive="active" [routerLinkActiveOptions]="{ exact: true }">
          {{ staff ? 'Tous les paiements' : 'Mes paiements' }}
        </a>
        <a routerLink="new" routerLinkActive="active">Payer une réservation</a>
        <a routerLink="invoices" routerLinkActive="active">{{ staff ? 'Factures' : 'Mes factures' }}</a>
      </nav>
      <router-outlet />
    </section>
  `,
  styles: `
    .payments { padding: 24px 32px; max-width: 1200px; }
    .payments h1 { margin: 0 0 16px; }
    .payments .tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 20px; }
    .payments .tabs a { padding: 8px 14px; color: var(--muted); text-decoration: none; border-bottom: 2px solid transparent; }
    .payments .tabs a.active { color: var(--primary); border-color: var(--primary); font-weight: 600; }
    .payments .card { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    .payments table { width: 100%; border-collapse: collapse; background: #fff; }
    .payments th, .payments td { text-align: left; padding: 10px 8px; border-bottom: 1px solid var(--border); font-size: 14px; }
    .payments th { color: var(--muted); font-weight: 600; }
    .payments td.num, .payments th.num { text-align: right; white-space: nowrap; }
    .payments code { font-size: 12px; color: var(--muted); }
    .payments .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; }
    .payments .badge.SUCCEEDED { background: #dcfce7; color: #166534; }
    .payments .badge.PENDING { background: #fef9c3; color: #854d0e; }
    .payments .badge.FAILED { background: #fee2e2; color: #991b1b; }
    .payments .badge.REFUNDED { background: #e0e7ff; color: #3730a3; }
    .payments .filters, .payments .actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: end; margin-bottom: 12px; }
    .payments label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: var(--muted); }
    .payments input, .payments select { padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; font: inherit; color: #0f172a; }
    .payments form.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
    .payments button, .payments a.button { padding: 8px 14px; border-radius: 8px; border: 1px solid var(--border); background: #fff; cursor: pointer; font: inherit; color: inherit; text-decoration: none; }
    .payments button.primary, .payments a.button.primary { background: var(--primary); border-color: var(--primary); color: #fff; }
    .payments button.danger { color: #991b1b; }
    .payments button:disabled { opacity: .5; cursor: default; }
    .payments .small { padding: 4px 8px; font-size: 13px; }
    .payments .alert { padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; }
    .payments .alert.error { background: #fee2e2; color: #7f1d1d; }
    .payments .alert.success { background: #dcfce7; color: #14532d; }
    .payments .alert.info { background: #e0f2fe; color: #0c4a6e; }
    .payments .alert ul { margin: 6px 0 0; padding-left: 18px; }
    .payments .hint, .payments .muted { color: var(--muted); font-size: 13px; }
    .payments .field-error { color: #991b1b; font-size: 12px; }
    .payments .pager { display: flex; gap: 12px; align-items: center; justify-content: flex-end; margin-top: 12px; }
    @media print { .payments .tabs, .payments .no-print { display: none; } }
  `,
})
export class PaymentsLayout {
  protected readonly staff = hasRole('AGENT', 'ADMIN');
}
