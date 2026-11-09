import { DatePipe, DecimalPipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { errorMessage } from '../../core/api';
import { hasRole } from '../../core/keycloak';
import { IdempotentSubmit, errorDetails } from './idempotent-submit';
import { FEE_LABELS, Payment, STATUS_LABELS, TYPE_LABELS } from './payment.models';
import { PaymentsService } from './payments.service';

/**
 * Settle a PENDING payment (/payments/:id/pay): extra fee after the vehicle return,
 * or a transfer the client finally pays by card. A refused card leaves it PENDING (422).
 */
@Component({
  selector: 'app-payment-pay',
  imports: [ReactiveFormsModule, RouterLink, DatePipe, DecimalPipe],
  template: `
    <p><a routerLink="../..">← Retour aux paiements</a></p>
    @if (loadError()) { <div class="alert error">{{ loadError() }}</div> }

    @if (payment(); as p) {
      <div class="card">
        <h2>{{ typeLabels[p.type] }} @if (p.feeType) { — {{ feeLabels[p.feeType] }} }</h2>
        <p>
          Montant : <strong>{{ p.amount | number: '1.3-3' }} {{ p.currency }}</strong> ·
          Réservation <code>{{ p.reservationId }}</code> ·
          créé le {{ p.createdAt | date: 'dd/MM/yyyy' }} ·
          <span class="badge" [class]="p.status">{{ statusLabels[p.status] }}</span>
        </p>
      </div>

      @if (error()) {
        <div class="alert error">
          {{ error() }}
          @if (details().length) { <ul>@for (d of details(); track d) { <li>{{ d }}</li> }</ul> }
        </div>
      }

      @if (p.status === 'PENDING') {
        <div class="card">
          <form class="grid" [formGroup]="form" (ngSubmit)="submit(p)">
            <label>Moyen de paiement
              <select formControlName="method">
                <option value="CARD">Carte</option>
                @if (staff) { <option value="CASH">Espèces (agence)</option> }
              </select>
            </label>
            @if (form.controls.method.value === 'CARD') {
              <label>Carte (simulation)
                <input formControlName="cardToken" autocomplete="off" />
                <span class="hint">Jeton de test ; <code>fail_…</code> simule un refus.</span>
              </label>
            }
            <div class="actions">
              <button type="submit" class="primary" [disabled]="sending()">
                {{ sending() ? 'Paiement en cours…' : 'Payer ' + (p.amount | number: '1.3-3') + ' ' + p.currency }}
              </button>
            </div>
          </form>
        </div>
      } @else if (p.status === 'SUCCEEDED') {
        <div class="alert success">Paiement réglé (référence <code>{{ p.transactionRef }}</code>).</div>
      } @else {
        <div class="alert info">Ce paiement n'est plus en attente.</div>
      }
    }
  `,
})
export class PaymentPay implements OnInit {
  private readonly api = inject(PaymentsService);
  /** Route parameter :id. */
  private readonly routeId = inject(ActivatedRoute).snapshot.paramMap.get('id') ?? '';

  protected readonly staff = hasRole('AGENT', 'ADMIN');
  protected readonly typeLabels = TYPE_LABELS;
  protected readonly statusLabels = STATUS_LABELS;
  protected readonly feeLabels = FEE_LABELS;
  protected readonly form = inject(FormBuilder).nonNullable.group({
    method: ['CARD' as 'CARD' | 'CASH'],
    cardToken: ['tok_visa_4242'],
  });
  protected readonly idempotency = new IdempotentSubmit();
  protected readonly payment = signal<Payment | null>(null);
  protected readonly loadError = signal<string | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly details = signal<string[]>([]);
  protected readonly sending = signal(false);

  ngOnInit(): void {
    this.api.getPayment(this.routeId).subscribe({
      next: (p) => this.payment.set(p),
      error: (err) => this.loadError.set(errorMessage(err)),
    });
  }

  protected submit(p: Payment): void {
    const { method, cardToken } = this.form.getRawValue();
    this.sending.set(true);
    this.error.set(null);
    this.details.set([]);
    this.api.payPending(p.id, { method, ...(method === 'CARD' ? { cardToken: cardToken.trim() } : {}) },
      this.idempotency.key()).subscribe({
      next: (paid) => {
        this.payment.set(paid);
        this.idempotency.succeeded();
        this.sending.set(false);
      },
      error: (err) => {
        this.error.set(errorMessage(err));
        this.details.set(errorDetails(err));
        this.idempotency.failed(err);
        this.sending.set(false);
      },
    });
  }
}
