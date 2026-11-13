import { DecimalPipe } from '@angular/common';
import { Component, effect, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { errorMessage } from '../../core/api';
import { LoyaltyApi } from './loyalty.api';
import { DiscountPreview } from './loyalty.model';

/**
 * Champ « Code promo » réutilisable (page de réservation d'Aziz) :
 * appelle /api/promo-codes/check (rien n'est consommé) puis affiche le prix barré et le nouveau prix.
 *
 *   <app-promo-code-input [amount]="totalPrice" (applied)="promoCode = $event?.code ?? null" />
 *
 * L'utilisation réelle est enregistrée par reservation-service (/internal/promo-codes/apply) à la réservation.
 */
@Component({
  selector: 'app-promo-code-input',
  imports: [FormsModule, DecimalPipe],
  styleUrl: './loyalty.scss',
  template: `
    <div class="promo">
      <label>Code promo
        <span class="row">
          <input [(ngModel)]="code" name="promoCode" placeholder="ex. BIENVENUE10" (keydown.enter)="check(); $event.preventDefault()" />
          <button type="button" (click)="check()" [disabled]="!code.trim() || loading()">Appliquer</button>
          @if (preview()) { <button type="button" (click)="clear()">Retirer</button> }
        </span>
      </label>
      @if (error()) { <div class="err">{{ error() }}</div> }
      @if (preview(); as p) {
        <p>
          <span class="strike">{{ p.amountBefore | number: '1.3-3' }} TND</span>
          <span class="new-price">{{ p.amountAfter | number: '1.3-3' }} TND</span>
          <span class="muted"> ({{ p.code }} : -{{ p.discount | number: '1.3-3' }} TND)</span>
        </p>
      }
    </div>
  `,
})
export class PromoCodeInput {
  private readonly api = inject(LoyaltyApi);

  /** Montant de la réservation avant réduction (TND). */
  readonly amount = input.required<number>();
  /** Réduction acceptée, ou null quand le code est retiré ou refusé. */
  readonly applied = output<DiscountPreview | null>();

  protected code = '';
  protected readonly preview = signal<DiscountPreview | null>(null);
  protected readonly error = signal('');
  protected readonly loading = signal(false);

  constructor() {
    // Le montant change (autres dates, autre véhicule) : l'aperçu n'est plus juste.
    effect(() => {
      this.amount();
      if (this.preview()) this.clear();
    });
  }

  protected check(): void {
    if (!this.code.trim() || !(this.amount() > 0)) return;
    this.loading.set(true);
    this.error.set('');
    this.api.check(this.code.trim(), this.amount()).subscribe({
      next: (p) => {
        this.loading.set(false);
        this.preview.set(p);
        this.applied.emit(p);
      },
      error: (e) => {
        this.loading.set(false);
        this.preview.set(null);
        this.error.set(errorMessage(e));
        this.applied.emit(null);
      },
    });
  }

  protected clear(): void {
    this.preview.set(null);
    this.error.set('');
    this.applied.emit(null);
  }
}
