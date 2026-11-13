import { DatePipe, DecimalPipe } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Page, errorMessage } from '../../core/api';
import { hasRole } from '../../core/keycloak';
import { LoyaltyApi } from './loyalty.api';
import { PromoCode, PromoState, STATE_LABELS } from './loyalty.model';

/** Codes promo (ADMIN ; AGENT en lecture) : filtre actif / expiré, compteur usedCount / maxUses, actions. */
@Component({
  selector: 'app-promo-code-list',
  imports: [FormsModule, RouterLink, DatePipe, DecimalPipe],
  styleUrl: './loyalty.scss',
  template: `
    <div class="page">
      <div class="head">
        <h1>Codes promo</h1>
        <div class="row">
          <a class="btn" routerLink="/loyalty/admin/accounts">Comptes clients</a>
          @if (isAdmin) { <a class="btn primary" routerLink="/loyalty/admin/promo-codes/new">Nouveau code</a> }
        </div>
      </div>

      <div class="card row">
        <label>État
          <select [(ngModel)]="state" (ngModelChange)="load(0)">
            <option value="">Tous</option>
            <option value="VALID">Valides</option>
            <option value="UPCOMING">À venir</option>
            <option value="EXPIRED">Expirés</option>
            <option value="EXHAUSTED">Épuisés</option>
            <option value="INACTIVE">Désactivés</option>
          </select>
        </label>
      </div>

      @if (error()) { <div class="alert">{{ error() }}</div> }

      <section class="card">
        @if (data(); as p) {
          <p class="muted">{{ shown().length }} code(s)</p>
          @if (shown().length > 0) {
            <table>
              <thead><tr><th>Code</th><th>Réduction</th><th>Conditions</th><th>Validité</th><th>Utilisations</th><th>État</th><th></th></tr></thead>
              <tbody>
                @for (c of shown(); track c.id) {
                  <tr>
                    <td><code>{{ c.code }}</code></td>
                    <td>{{ c.discountType === 'PERCENT' ? (c.discountValue | number: '1.0-3') + ' %' : (c.discountValue | number: '1.3-3') + ' TND' }}</td>
                    <td>
                      @if (c.minAmount) { <span class="badge">≥ {{ c.minAmount | number: '1.3-3' }} TND</span> }
                      @if (c.minTier) { <span class="badge" [class]="'badge ' + c.minTier">{{ c.minTier }}</span> }
                      @if (!c.minAmount && !c.minTier) { <span class="muted">—</span> }
                    </td>
                    <td>{{ c.validFrom | date: 'dd/MM/yyyy' }} → {{ c.validUntil | date: 'dd/MM/yyyy' }}</td>
                    <td>{{ c.usedCount }} / {{ c.maxUses ?? '∞' }}</td>
                    <td><span class="badge" [class.green]="c.state === 'VALID'" [class.red]="c.state === 'EXPIRED' || c.state === 'INACTIVE'"
                              [class.amber]="c.state === 'EXHAUSTED' || c.state === 'UPCOMING'">{{ labels[c.state] }}</span></td>
                    <td class="row">
                      @if (isAdmin) {
                        <a class="btn" [routerLink]="['/loyalty/admin/promo-codes', c.id, 'edit']">Modifier</a>
                        @if (c.active) { <button type="button" (click)="deactivate(c)">Désactiver</button> }
                        <button type="button" class="danger" (click)="remove(c)">Supprimer</button>
                      }
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          } @else { <p class="muted">Aucun code.</p> }
          <div class="pager">
            <button type="button" [disabled]="p.page === 0" (click)="load(p.page - 1)">Précédent</button>
            <span class="muted">Page {{ p.page + 1 }} / {{ pages(p) }}</span>
            <button type="button" [disabled]="p.page + 1 >= pages(p)" (click)="load(p.page + 1)">Suivant</button>
          </div>
        } @else { <p class="muted">Chargement...</p> }
      </section>
    </div>
  `,
})
export class PromoCodeList implements OnInit {
  private readonly api = inject(LoyaltyApi);
  private readonly size = 50;
  protected readonly isAdmin = hasRole('ADMIN');
  protected readonly labels = STATE_LABELS;
  protected readonly data = signal<Page<PromoCode> | null>(null);
  protected readonly error = signal('');
  protected state: PromoState | '' = '';
  private readonly stateFilter = signal<PromoState | ''>('');
  /** L'état (expiré, épuisé...) est calculé par le service ; le filtre s'applique sur la page chargée. */
  protected readonly shown = computed(() => {
    const items = this.data()?.items ?? [];
    const s = this.stateFilter();
    return s ? items.filter((c) => c.state === s) : items;
  });

  ngOnInit(): void {
    this.load(0);
  }

  protected load(page: number): void {
    this.error.set('');
    this.stateFilter.set(this.state);
    const active = this.state === 'INACTIVE' ? false : null;
    this.api.promoCodes({ active, page, size: this.size }).subscribe({
      next: (p) => this.data.set(p),
      error: (e) => this.error.set(errorMessage(e)),
    });
  }

  protected deactivate(c: PromoCode): void {
    const { id, code, state, usedCount, ownerUserId, createdAt, ...rest } = c;
    this.api.updatePromoCode(id, { ...rest, active: false }).subscribe({
      next: () => this.load(this.data()?.page ?? 0),
      error: (e) => this.error.set(errorMessage(e)),
    });
  }

  protected remove(c: PromoCode): void {
    if (!confirm(`Supprimer le code ${c.code} ?`)) return;
    this.api.deletePromoCode(c.id).subscribe({
      next: () => this.load(this.data()?.page ?? 0),
      error: (e) => this.error.set(errorMessage(e)),   // 409 : déjà utilisé, il faut le désactiver
    });
  }

  protected pages(p: Page<unknown>): number {
    return Math.max(1, Math.ceil(p.total / p.size));
  }
}
