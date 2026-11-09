import { HttpClient, HttpHeaders, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { Page } from '../../core/api';
import {
  CreatePaymentRequest,
  Invoice,
  PayPendingRequest,
  Payment,
  PaymentFilters,
} from './payment.models';

/**
 * Calls payment-service through the Gateway (/api/... only, see frontend/README.md).
 * The Keycloak token is added by core/auth.ts (authInterceptor).
 */
@Injectable({ providedIn: 'root' })
export class PaymentsService {
  private readonly http = inject(HttpClient);

  listPayments(filters: PaymentFilters, page = 0, size = 20): Observable<Page<Payment>> {
    return this.http.get<Page<Payment>>('/api/payments', { params: pageParams(filters, page, size) });
  }

  getPayment(id: string): Observable<Payment> {
    return this.http.get<Payment>(`/api/payments/${id}`);
  }

  /**
   * POST /api/payments. The Idempotency-Key is chosen by the caller: the SAME key must be
   * reused when retrying the same payment (network error, 503), a NEW key for a new payment.
   */
  createPayment(body: CreatePaymentRequest, idempotencyKey: string): Observable<Payment> {
    return this.http.post<Payment>('/api/payments', body, { headers: idempotency(idempotencyKey) });
  }

  payPending(id: string, body: PayPendingRequest, idempotencyKey: string): Observable<Payment> {
    return this.http.post<Payment>(`/api/payments/${id}/pay`, body, { headers: idempotency(idempotencyKey) });
  }

  /** ADMIN: validate (SUCCEEDED) or reject (FAILED) a PENDING payment, e.g. a bank transfer. */
  decide(id: string, status: 'SUCCEEDED' | 'FAILED', transactionRef?: string): Observable<Payment> {
    return this.http.patch<Payment>(`/api/payments/${id}`, { status, ...(transactionRef ? { transactionRef } : {}) });
  }

  /** ADMIN: only PENDING or FAILED payments not on an invoice. */
  deletePayment(id: string): Observable<void> {
    return this.http.delete<void>(`/api/payments/${id}`);
  }

  listInvoices(page = 0, size = 20, reservationId?: string): Observable<Page<Invoice>> {
    return this.http.get<Page<Invoice>>('/api/invoices', { params: pageParams({ reservationId }, page, size) });
  }

  getInvoice(id: string): Observable<Invoice> {
    return this.http.get<Invoice>(`/api/invoices/${id}`);
  }
}

/** New Idempotency-Key (UUID v4) for a new payment intent. */
export function newIdempotencyKey(): string {
  return crypto.randomUUID();
}

function idempotency(key: string): HttpHeaders {
  return new HttpHeaders({ 'Idempotency-Key': key });
}

function pageParams(filters: object, page: number, size: number): HttpParams {
  let params = new HttpParams().set('page', page).set('size', size);
  for (const [name, value] of Object.entries(filters) as [string, string | undefined][]) {
    if (value) {
      params = params.set(name, value.trim());
    }
  }
  return params;
}
