import { DatePipe, DecimalPipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Page, errorMessage } from '../../core/api';
import { applyServerErrors, fieldMessage } from './form-errors';
import { LoyaltyApi } from './loyalty.api';
import { DiscountPreview, LoyaltyAccount, PointTransaction, PromoCode, TRANSACTION_LABELS } from './loyalty.model';
import { PromoCodeInput } from './promo-code-input';

/**
 * « Mes points » (CLIENT) : carte du niveau, jauge vers le niveau suivant, historique paginé,
 * conversion des points en bon personnel, mes bons, et un essai de code promo.
 */
@Component({
  selector: 'app-my-points',
  imports: [ReactiveFormsModule, DatePipe, DecimalPipe, PromoCodeInput],
  styleUrl: './loyalty.scss',
  template: `
    <div class="page">
      <div class="head"><h1>Mes points</h1></div>
      @if (error()) { <div class="alert">{{ error() }}</div> }
      @if (success()) { <div class="alert ok">{{ success() }}</div> }

      @if (account(); as a) {
        <section class="tier-card" [class]="'tier-card tier-' + a.tier">
          <div>
            <div class="label">Niveau</div>
            <div class="big">{{ a.tier }}</div>
            <div>{{ a.lifetimePoints }} points cumulés</div>
          </div>
          <div>
            <div class="label">Solde</div>
            <div class="big">{{ a.balance }} pts</div>
            <div>soit {{ a.balance / 10 | number: '1.0-0' }} TND en bons</div>
          </div>
        </section>

        <section class="card">
          @if (a.nextTier) {
            <h2>Vers {{ a.nextTier }}</h2>
            <div class="gauge"><div [style.width.%]="a.progress * 100"></div></div>
            <p class="muted">Encore {{ a.pointsToNextTier }} points cumulés pour passer {{ a.nextTier }}.
              Les points dépensés ne font jamais descendre de niveau.</p>
          } @else {
            <h2>Niveau maximal atteint</h2>
            <div class="gauge"><div style="width: 100%"></div></div>
            <p class="muted">GOLD : vos locations rapportent 1,5 fois plus de points.</p>
          }
        </section>

        <div class="cols">
          <section class="card">
            <h2>Convertir mes points</h2>
            <p class="muted">Par multiples de 100 : 100 points = 10 TND, bon valable 90 jours, utilisable une fois.</p>
            <form class="row" [formGroup]="redeemForm" (ngSubmit)="redeem()">
              <label>Points
                <input type="number" step="100" min="100" formControlName="points" />
                <span class="err">{{ msg(redeemForm.controls.points) }}</span>
              </label>
              <button type="submit" class="primary" [disabled]="redeemForm.invalid || busy()">Convertir</button>
            </form>
          </section>

          <section class="card">
            <h2>Mes bons</h2>
            @for (v of vouchers(); track v.id) {
              <div class="voucher"><code>{{ v.code }}</code>
                <span>{{ v.discountValue | number: '1.3-3' }} TND</span>
                <span class="muted">jusqu'au {{ v.validUntil | date: 'dd/MM/yyyy' }}</span></div>
            } @empty { <p class="muted">Aucun bon disponible.</p> }
          </section>
        </div>

        <section class="card">
          <h2>Essayer un code promo</h2>
          <label>Montant d'une réservation (TND) <input type="number" [value]="tryAmount()" (input)="setAmount($event)" /></label>
          <app-promo-code-input [amount]="tryAmount()" (applied)="tried.set($event)" />
        </section>
      }

      <section class="card">
        <h2>Historique</h2>
        @if (history(); as h) {
          @if (h.items.length > 0) {
            <table>
              <thead><tr><th>Date</th><th>Type</th><th>Motif</th><th>Points</th></tr></thead>
              <tbody>
                @for (t of h.items; track t.id) {
                  <tr>
                    <td>{{ t.createdAt | date: 'dd/MM/yyyy HH:mm' }}</td>
                    <td><span class="badge">{{ labels[t.type] }}</span></td>
                    <td>{{ t.reason }}</td>
                    <td [class.plus]="t.points > 0" [class.minus]="t.points < 0">{{ t.points > 0 ? '+' : '' }}{{ t.points }}</td>
                  </tr>
                }
              </tbody>
            </table>
            <div class="pager">
              <button type="button" [disabled]="h.page === 0" (click)="loadHistory(h.page - 1)">Précédent</button>
              <span class="muted">Page {{ h.page + 1 }} / {{ pages(h) }}</span>
              <button type="button" [disabled]="h.page + 1 >= pages(h)" (click)="loadHistory(h.page + 1)">Suivant</button>
            </div>
          } @else { <p class="muted">Aucun mouvement.</p> }
        } @else { <p class="muted">Chargement...</p> }
      </section>
    </div>
  `,
})
export class MyPoints implements OnInit {
  private readonly api = inject(LoyaltyApi);
  private readonly size = 10;
  protected readonly labels = TRANSACTION_LABELS;
  protected readonly account = signal<LoyaltyAccount | null>(null);
  protected readonly history = signal<Page<PointTransaction> | null>(null);
  protected readonly vouchers = signal<PromoCode[]>([]);
  protected readonly error = signal('');
  protected readonly success = signal('');
  protected readonly busy = signal(false);
  protected readonly tryAmount = signal(450);
  protected readonly tried = signal<DiscountPreview | null>(null);

  protected readonly redeemForm = inject(NonNullableFormBuilder).group({
    points: [100, [Validators.required, Validators.min(100)]],
  });

  ngOnInit(): void {
    this.reload();
  }

  protected reload(): void {
    this.api.me().subscribe({ next: (a) => this.account.set(a), error: (e) => this.error.set(errorMessage(e)) });
    this.api.myVouchers().subscribe({ next: (v) => this.vouchers.set(v), error: () => this.vouchers.set([]) });
    this.loadHistory(0);
  }

  protected loadHistory(page: number): void {
    this.api.myTransactions(page, this.size).subscribe({
      next: (h) => this.history.set(h),
      error: (e) => this.error.set(errorMessage(e)),
    });
  }

  protected redeem(): void {
    this.busy.set(true);
    this.error.set('');
    this.success.set('');
    this.api.redeem(this.redeemForm.getRawValue().points).subscribe({
      next: (v) => {
        this.busy.set(false);
        this.success.set(`Bon ${v.code} de ${v.discountValue.toFixed(3)} TND créé, valable jusqu'au ${v.validUntil}.`);
        this.reload();
      },
      error: (e) => {
        this.busy.set(false);
        this.error.set(applyServerErrors(this.redeemForm, e));
      },
    });
  }

  protected setAmount(event: Event): void {
    this.tryAmount.set(Number((event.target as HTMLInputElement).value) || 0);
  }

  protected msg = fieldMessage;

  protected pages(p: Page<unknown>): number {
    return Math.max(1, Math.ceil(p.total / p.size));
  }
}
