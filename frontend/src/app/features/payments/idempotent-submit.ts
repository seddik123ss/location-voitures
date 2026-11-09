import { HttpErrorResponse } from '@angular/common/http';
import { signal } from '@angular/core';

import { newIdempotencyKey } from './payments.service';

/**
 * Idempotency-Key lifecycle for one payment form (see payment-service README):
 * - one key per payment intent, kept while the user retries after an uncertain failure
 *   (network error, 502, 503, 504): if the first attempt did reach the server, the retry
 *   gets 409 instead of charging twice;
 * - a new key once the server gave a definitive answer (success or 4xx), so the next
 *   payment is a new intent.
 */
export class IdempotentSubmit {
  readonly key = signal(newIdempotencyKey());
  readonly retryWithSameKey = signal(false);

  succeeded(): void {
    this.renew();
  }

  failed(err: unknown): void {
    const status = err instanceof HttpErrorResponse ? err.status : 0;
    const uncertain = status === 0 || status === 502 || status === 503 || status === 504;
    if (uncertain) {
      this.retryWithSameKey.set(true);
    } else {
      this.renew();
    }
  }

  /** The user changed the payment (amount, reservation...): it is a new intent. */
  changed(): void {
    if (this.retryWithSameKey()) {
      this.renew();
    }
  }

  private renew(): void {
    this.key.set(newIdempotencyKey());
    this.retryWithSameKey.set(false);
  }
}

/** Field errors of the common error format, as "field: message" lines. */
export function errorDetails(err: unknown): string[] {
  const details = (err as { error?: { details?: { field: string | null; message: string }[] } })?.error?.details;
  return (details ?? []).map((d) => (d.field ? `${d.field} : ${d.message}` : d.message));
}
