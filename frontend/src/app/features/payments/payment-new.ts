import { DecimalPipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { errorMessage } from '../../core/api';
import { hasRole } from '../../core/keycloak';
import { IdempotentSubmit, errorDetails } from './idempotent-submit';
import { CreatePaymentRequest, METHOD_LABELS, Payment, PaymentMethod, STATUS_LABELS, TYPE_LABELS } from './payment.models';
import { PaymentsService } from './payments.service';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const AMOUNT = /^\d+(\.\d{1,3})?$/; // TND, at most 3 decimals

/**
 * Pay a reservation (rental price or deposit).
 * Prefilled from the URL: /payments/new?reservationId=...&type=RENTAL&amount=270
 * (the reservations module can link here). The card is simulated: only a test token is sent,
 * never a card number; a token starting with "fail_" is refused by payment-service.
 */
@Component({
  selector: 'app-payment-new',
  imports: [ReactiveFormsModule, RouterLink, DecimalPipe],
  templateUrl: './payment-new.html',
})
export class PaymentNew implements OnInit {
  private readonly api = inject(PaymentsService);
  private readonly route = inject(ActivatedRoute);

  protected readonly staff = hasRole('AGENT', 'ADMIN');
  protected readonly methods: PaymentMethod[] = this.staff ? ['CARD', 'CASH', 'TRANSFER'] : ['CARD', 'TRANSFER'];
  protected readonly methodLabels = METHOD_LABELS;
  protected readonly statusLabels = STATUS_LABELS;
  protected readonly typeLabels = TYPE_LABELS;

  protected readonly form = inject(FormBuilder).nonNullable.group({
    reservationId: ['', [Validators.required, Validators.pattern(UUID)]],
    type: ['RENTAL' as 'RENTAL' | 'DEPOSIT', Validators.required],
    method: ['CARD' as PaymentMethod, Validators.required],
    amount: ['', [Validators.required, Validators.pattern(AMOUNT)]],
    cardToken: ['tok_visa_4242'],
  });

  protected readonly idempotency = new IdempotentSubmit();
  protected readonly sending = signal(false);
  protected readonly result = signal<Payment | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly details = signal<string[]>([]);

  constructor() {
    this.form.valueChanges.pipe(takeUntilDestroyed()).subscribe(() => this.idempotency.changed());
  }

  ngOnInit(): void {
    const q = this.route.snapshot.queryParamMap;
    this.form.patchValue({
      reservationId: q.get('reservationId') ?? '',
      type: q.get('type') === 'DEPOSIT' ? 'DEPOSIT' : 'RENTAL',
      amount: q.get('amount') ?? '',
    });
  }

  protected get isCard(): boolean {
    return this.form.controls.method.value === 'CARD';
  }

  protected submit(): void {
    if (this.form.invalid || (this.isCard && !this.form.controls.cardToken.value.trim())) {
      this.form.markAllAsTouched();
      return;
    }
    const v = this.form.getRawValue();
    const body: CreatePaymentRequest = {
      reservationId: v.reservationId.trim(),
      type: v.type,
      method: v.method,
      amount: Number(v.amount),
      ...(v.method === 'CARD' ? { cardToken: v.cardToken.trim() } : {}),
    };
    this.sending.set(true);
    this.result.set(null);
    this.error.set(null);
    this.details.set([]);
    this.api.createPayment(body, this.idempotency.key()).subscribe({
      next: (payment) => {
        this.result.set(payment);
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

  protected invalid(name: keyof typeof this.form.controls): boolean {
    const control = this.form.controls[name];
    return control.invalid && control.touched;
  }
}
