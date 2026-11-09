import { DatePipe, DecimalPipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { errorMessage } from '../../core/api';
import { hasRole } from '../../core/keycloak';
import { Contract, ContractStatus, ContractsService } from './contracts.service';

/** Liste des contrats : "Mes contrats" pour un CLIENT, tous les contrats pour AGENT / ADMIN. */
@Component({
  selector: 'app-contract-list',
  imports: [RouterLink, DatePipe, DecimalPipe, FormsModule],
  styleUrl: './contracts.scss',
  template: `
    <div class="head">
      <h1>{{ isStaff ? 'Contrats et retours' : 'Mes contrats' }}</h1>
      <div class="actions">
        <select [(ngModel)]="status" (ngModelChange)="load(0)" aria-label="Filtrer par statut">
          <option [ngValue]="undefined">Tous les statuts</option>
          <option value="ACTIVE">En cours</option>
          <option value="CLOSED">Clôturés</option>
        </select>
        @if (isStaff) {
          <a class="btn" routerLink="new">Remise des clés</a>
        }
      </div>
    </div>

    @if (error()) {
      <div class="error">{{ error() }}</div>
    }

    @if (loading()) {
      <p class="muted">Chargement…</p>
    } @else if (contracts().length === 0) {
      <div class="card muted">Aucun contrat.</div>
    } @else {
      <table>
        <thead>
          <tr>
            <th>Numéro</th>
            <th>Signé le</th>
            <th>Statut</th>
            <th>Clôturé le</th>
            <th class="num">Frais (TND)</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @for (c of contracts(); track c.id) {
            <tr>
              <td>{{ c.number }}</td>
              <td>{{ c.signedAt | date: 'dd/MM/yyyy HH:mm' }}</td>
              <td><span class="badge" [class]="c.status">{{ c.status === 'ACTIVE' ? 'En cours' : 'Clôturé' }}</span></td>
              <td>{{ c.closedAt ? (c.closedAt | date: 'dd/MM/yyyy HH:mm') : '—' }}</td>
              <td class="num">{{ c.totalExtraFees | number: '1.3-3' : 'fr' }}</td>
              <td><a [routerLink]="c.id">Détails</a></td>
            </tr>
          }
        </tbody>
      </table>

      <div class="pager">
        <button class="secondary" [disabled]="page() === 0" (click)="load(page() - 1)">Précédent</button>
        <span class="muted">Page {{ page() + 1 }} / {{ pages() }} — {{ total() }} contrat(s)</span>
        <button class="secondary" [disabled]="page() + 1 >= pages()" (click)="load(page() + 1)">Suivant</button>
      </div>
    }
  `,
})
export class ContractList implements OnInit {
  private readonly api = inject(ContractsService);
  private readonly size = 10;

  protected readonly isStaff = hasRole('AGENT', 'ADMIN');
  protected status: ContractStatus | undefined;

  protected readonly contracts = signal<Contract[]>([]);
  protected readonly page = signal(0);
  protected readonly total = signal(0);
  protected readonly pages = signal(1);
  protected readonly loading = signal(true);
  protected readonly error = signal('');

  ngOnInit(): void {
    this.load(0);
  }

  load(page: number): void {
    this.loading.set(true);
    this.error.set('');
    this.api.list(page, this.size, this.status).subscribe({
      next: (p) => {
        this.contracts.set(p.items);
        this.page.set(p.page);
        this.total.set(p.total);
        this.pages.set(Math.max(1, Math.ceil(p.total / p.size)));
        this.loading.set(false);
      },
      error: (e) => {
        this.error.set(errorMessage(e));
        this.loading.set(false);
      },
    });
  }
}
