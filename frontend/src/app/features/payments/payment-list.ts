import { DatePipe, DecimalPipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Observable } from 'rxjs';

import { Page, errorMessage } from '../../core/api';
import { currentUserId, hasRole } from '../../core/keycloak';
import {
  FEE_LABELS,
  METHOD_LABELS,
  Payment,
  PaymentFilters,
  PaymentStatus,
  PaymentType,
  STATUS_LABELS,
  TYPE_LABELS,
} from './payment.models';
import { PaymentsService } from './payments.service';

/**
 * Payment history. CLIENT: own payments (filtered by payment-service from the token).
 * AGENT/ADMIN: every payment. ADMIN: validate/reject a PENDING payment, delete PENDING/FAILED.
 */
@Component({
  selector: 'app-payment-list',
  imports: [FormsModule, RouterLink, DatePipe, DecimalPipe],
  templateUrl: './payment-list.html',
})
export class PaymentList implements OnInit {
  private readonly api = inject(PaymentsService);

  protected readonly staff = hasRole('AGENT', 'ADMIN');
  protected readonly admin = hasRole('ADMIN');
  protected readonly me = currentUserId();
  protected readonly statusLabels = STATUS_LABELS;
  protected readonly typeLabels = TYPE_LABELS;
  protected readonly methodLabels = METHOD_LABELS;
  protected readonly feeLabels = FEE_LABELS;
  protected readonly statuses: PaymentStatus[] = ['PENDING', 'SUCCEEDED', 'FAILED', 'REFUNDED'];
  protected readonly types: PaymentType[] = ['RENTAL', 'DEPOSIT', 'EXTRA_FEE', 'REFUND'];

  protected filters: PaymentFilters = { reservationId: '', status: '', type: '' };
  protected readonly page = signal<Page<Payment> | null>(null);
  protected readonly loading = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly notice = signal<string | null>(null);
  private pageIndex = 0;
  protected readonly size = 20;

  ngOnInit(): void {
    this.load(0);
  }

  protected load(pageIndex = this.pageIndex): void {
    this.pageIndex = pageIndex;
    this.loading.set(true);
    this.error.set(null);
    this.api.listPayments(this.filters, pageIndex, this.size).subscribe({
      next: (page) => {
        this.page.set(page);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(errorMessage(err));
        this.loading.set(false);
      },
    });
  }

  protected resetFilters(): void {
    this.filters = { reservationId: '', status: '', type: '' };
    this.load(0);
  }

  protected lastPage(page: Page<Payment>): boolean {
    return (page.page + 1) * page.size >= page.total;
  }

  /** A PENDING payment can be settled by its owner (card) or by staff (card or cash). */
  protected canSettle(p: Payment): boolean {
    return p.status === 'PENDING' && p.type !== 'REFUND' && (this.staff || p.userId === this.me);
  }

  protected validate(p: Payment): void {
    const ref = window.prompt('Référence du virement reçu (facultatif) :', '') ?? undefined;
    this.run(this.api.decide(p.id, 'SUCCEEDED', ref || undefined), 'Paiement validé.');
  }

  protected reject(p: Payment): void {
    if (window.confirm('Rejeter ce paiement (statut FAILED) ?')) {
      this.run(this.api.decide(p.id, 'FAILED'), 'Paiement rejeté.');
    }
  }

  protected remove(p: Payment): void {
    if (window.confirm('Supprimer définitivement ce paiement ?')) {
      this.run(this.api.deletePayment(p.id), 'Paiement supprimé.');
    }
  }

  private run(action: Observable<unknown>, success: string): void {
    this.notice.set(null);
    this.error.set(null);
    action.subscribe({
      next: () => {
        this.notice.set(success);
        this.load();
      },
      error: (err) => this.error.set(errorMessage(err)),
    });
  }
}
