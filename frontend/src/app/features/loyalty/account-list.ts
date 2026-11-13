import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule, NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Page, errorMessage } from '../../core/api';
import { hasRole } from '../../core/keycloak';
import { applyServerErrors, fieldMessage } from './form-errors';
import { LoyaltyApi } from './loyalty.api';
import { LoyaltyAccount, TIERS, Tier } from './loyalty.model';

/** Comptes clients (AGENT, ADMIN) : liste filtrée par niveau, détail, ajustement de points (ADMIN). */
@Component({
  selector: 'app-account-list',
  imports: [FormsModule, ReactiveFormsModule, RouterLink, DatePipe],
  styleUrl: './loyalty.scss',
  template: `
    <div class="page">
      <div class="head">
        <h1>Comptes de fidélité</h1>
        <a class="btn" routerLink="/loyalty/admin/promo-codes">Codes promo</a>
      </div>

      <div class="card row">
        <label>Niveau
          <select [(ngModel)]="tier" (ngModelChange)="load(0)">
            <option value="">Tous</option>
            @for (t of tiers; track t) { <option [value]="t">{{ t }}</option> }
          </select>
        </label>
      </div>
      @if (error()) { <div class="alert">{{ error() }}</div> }

      <div class="cols">
        <section class="card">
          @if (data(); as p) {
            <p class="muted">{{ p.total }} compte(s)</p>
            <table>
              <thead><tr><th>Client</th><th>Niveau</th><th>Solde</th><th>Cumul</th><th>Statut</th></tr></thead>
              <tbody>
                @for (a of p.items; track a.userId) {
                  <tr (click)="select(a)" style="cursor: pointer" [style.background]="selected()?.userId === a.userId ? '#f0fdfa' : ''">
                    <td><code>{{ a.userId.slice(0, 8) }}…</code></td>
                    <td><span class="badge" [class]="'badge ' + a.tier">{{ a.tier }}</span></td>
                    <td>{{ a.balance }}</td>
                    <td>{{ a.lifetimePoints }}</td>
                    <td>@if (a.status === 'ACTIVE') { <span class="badge green">Actif</span> } @else { <span class="badge red">Fermé</span> }</td>
                  </tr>
                }
              </tbody>
            </table>
            <div class="pager">
              <button type="button" [disabled]="p.page === 0" (click)="load(p.page - 1)">Précédent</button>
              <span class="muted">Page {{ p.page + 1 }} / {{ pages(p) }}</span>
              <button type="button" [disabled]="p.page + 1 >= pages(p)" (click)="load(p.page + 1)">Suivant</button>
            </div>
          } @else { <p class="muted">Chargement...</p> }
        </section>

        <section class="card">
          @if (selected(); as a) {
            <h2>Compte {{ a.userId }}</h2>
            <dl>
              <dt>Niveau</dt><dd><span class="badge" [class]="'badge ' + a.tier">{{ a.tier }}</span></dd>
              <dt>Solde</dt><dd>{{ a.balance }} points</dd>
              <dt>Cumul</dt><dd>{{ a.lifetimePoints }} points</dd>
              <dt>Niveau suivant</dt><dd>{{ a.nextTier ? a.nextTier + ' (encore ' + a.pointsToNextTier + ' points)' : '—' }}</dd>
              <dt>Ouvert le</dt><dd>{{ a.createdAt | date: 'dd/MM/yyyy' }}</dd>
            </dl>
            @if (isAdmin && a.status === 'ACTIVE') {
              <h2 style="margin-top: 16px">Ajuster les points</h2>
              <form [formGroup]="adjustForm" (ngSubmit)="adjust(a)">
                <div class="grid">
                  <label>Points (+ ou -)
                    <input type="number" formControlName="points" />
                    <span class="err">{{ msg(adjustForm.controls.points) }}</span>
                  </label>
                  <label>Motif
                    <input formControlName="reason" placeholder="Geste commercial..." />
                    <span class="err">{{ msg(adjustForm.controls.reason) }}</span>
                  </label>
                </div>
                <div class="actions"><button type="submit" class="primary" [disabled]="adjustForm.invalid">Ajuster</button></div>
              </form>
            }
          } @else { <p class="muted">Cliquez sur un compte pour voir son détail.</p> }
        </section>
      </div>
    </div>
  `,
})
export class AccountList implements OnInit {
  private readonly api = inject(LoyaltyApi);
  private readonly size = 20;
  protected readonly isAdmin = hasRole('ADMIN');
  protected readonly tiers = TIERS;
  protected tier: Tier | '' = '';
  protected readonly data = signal<Page<LoyaltyAccount> | null>(null);
  protected readonly selected = signal<LoyaltyAccount | null>(null);
  protected readonly error = signal('');

  protected readonly adjustForm = inject(NonNullableFormBuilder).group({
    points: [0, Validators.required],
    reason: ['', [Validators.required, Validators.maxLength(255)]],
  });

  ngOnInit(): void {
    this.load(0);
  }

  protected load(page: number): void {
    this.error.set('');
    this.api.accounts({ tier: this.tier, page, size: this.size }).subscribe({
      next: (p) => this.data.set(p),
      error: (e) => this.error.set(errorMessage(e)),
    });
  }

  protected select(a: LoyaltyAccount): void {
    this.selected.set(a);
    this.adjustForm.reset();
  }

  protected adjust(a: LoyaltyAccount): void {
    const { points, reason } = this.adjustForm.getRawValue();
    this.api.adjust(a.userId, Number(points), reason).subscribe({
      next: (updated) => {
        this.selected.set(updated);
        this.adjustForm.reset();
        this.load(this.data()?.page ?? 0);
      },
      error: (e) => this.error.set(applyServerErrors(this.adjustForm, e)),
    });
  }

  protected msg = fieldMessage;

  protected pages(p: Page<unknown>): number {
    return Math.max(1, Math.ceil(p.total / p.size));
  }
}
