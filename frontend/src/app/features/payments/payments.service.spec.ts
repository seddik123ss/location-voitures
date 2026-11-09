import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { IdempotentSubmit } from './idempotent-submit';
import { PaymentsService } from './payments.service';

describe('PaymentsService', () => {
  let service: PaymentsService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(PaymentsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('lists payments through the Gateway with pagination and non-empty filters only', () => {
    service.listPayments({ reservationId: ' abc ', status: '', type: 'RENTAL' }, 2, 10).subscribe();
    const req = http.expectOne((r) => r.url === '/api/payments');
    expect(req.request.params.get('page')).toBe('2');
    expect(req.request.params.get('size')).toBe('10');
    expect(req.request.params.get('reservationId')).toBe('abc');
    expect(req.request.params.get('type')).toBe('RENTAL');
    expect(req.request.params.has('status')).toBe(false);
    req.flush({ items: [], page: 2, size: 10, total: 0 });
  });

  it('sends the Idempotency-Key header when creating a payment', () => {
    service.createPayment({ reservationId: 'r', type: 'RENTAL', method: 'CARD', amount: 270, cardToken: 't' }, 'key-1')
      .subscribe();
    const req = http.expectOne('/api/payments');
    expect(req.request.method).toBe('POST');
    expect(req.request.headers.get('Idempotency-Key')).toBe('key-1');
    expect(req.request.body.amount).toBe(270);
    req.flush({});
  });

  it('pays a pending payment and validates a transfer', () => {
    service.payPending('p1', { method: 'CASH' }, 'key-2').subscribe();
    const pay = http.expectOne('/api/payments/p1/pay');
    expect(pay.request.headers.get('Idempotency-Key')).toBe('key-2');
    pay.flush({});

    service.decide('p2', 'SUCCEEDED', 'VIR-1').subscribe();
    const patch = http.expectOne('/api/payments/p2');
    expect(patch.request.method).toBe('PATCH');
    expect(patch.request.body).toEqual({ status: 'SUCCEEDED', transactionRef: 'VIR-1' });
    patch.flush({});
  });
});

describe('IdempotentSubmit', () => {
  const httpError = (status: number) => new HttpErrorResponse({ status });

  it('keeps the same key after an uncertain failure (network, 502, 503, 504)', () => {
    for (const status of [0, 502, 503, 504]) {
      const submit = new IdempotentSubmit();
      const key = submit.key();
      submit.failed(httpError(status));
      expect(submit.key()).toBe(key);
      expect(submit.retryWithSameKey()).toBe(true);
    }
  });

  it('uses a new key after success or a definitive refusal (4xx)', () => {
    const submit = new IdempotentSubmit();
    const first = submit.key();
    submit.failed(httpError(422));
    expect(submit.key()).not.toBe(first);
    const second = submit.key();
    submit.succeeded();
    expect(submit.key()).not.toBe(second);
  });

  it('uses a new key when the user changes the payment after an uncertain failure', () => {
    const submit = new IdempotentSubmit();
    submit.failed(httpError(503));
    const kept = submit.key();
    submit.changed();
    expect(submit.key()).not.toBe(kept);
    expect(submit.retryWithSameKey()).toBe(false);
  });
});
