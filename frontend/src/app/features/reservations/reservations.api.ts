import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiError, errorMessage, Page } from '../../core/api';
import {
  BookingRequest,
  Quote,
  Reservation,
  ReservationFilter,
  ReservationStatus,
} from './reservations.models';

const BASE = '/api/reservations';

/** Appels à reservation-service, via la Gateway (proxy Angular). */
@Injectable({ providedIn: 'root' })
export class ReservationsApi {
  private readonly http = inject(HttpClient);

  list(filter: ReservationFilter): Observable<Page<Reservation>> {
    let params = new HttpParams().set('page', filter.page).set('size', filter.size);
    for (const key of ['status', 'userId', 'vehicleId'] as const) {
      const value = filter[key]?.trim();
      if (value) {
        params = params.set(key, value);
      }
    }
    return this.http.get<Page<Reservation>>(BASE, { params });
  }

  get(id: string): Observable<Reservation> {
    return this.http.get<Reservation>(`${BASE}/${id}`);
  }

  quote(body: BookingRequest): Observable<Quote> {
    return this.http.post<Quote>(`${BASE}/quote`, body);
  }

  create(body: BookingRequest): Observable<Reservation> {
    return this.http.post<Reservation>(BASE, body);
  }

  update(
    id: string,
    body: { startDate: string; endDate: string; returnAgencyId?: string | null },
  ): Observable<Reservation> {
    return this.http.put<Reservation>(`${BASE}/${id}`, body);
  }

  cancel(id: string, reason: string | null): Observable<Reservation> {
    return this.http.post<Reservation>(`${BASE}/${id}/cancel`, reason ? { reason } : {});
  }

  changeStatus(
    id: string,
    status: ReservationStatus,
    comment: string | null,
  ): Observable<Reservation> {
    return this.http.patch<Reservation>(`${BASE}/${id}/status`, { status, comment });
  }

  delete(id: string): Observable<void> {
    return this.http.delete<void>(`${BASE}/${id}`);
  }
}

/** Message de l'erreur, suivi du détail par champ s'il y en a (ex. « Déjà réservé du 2026-12-10 au 2026-12-13 »). */
export function reservationError(err: unknown): string {
  const body = (err as { error?: Partial<ApiError>; status?: number })?.error;
  if ((err as { status?: number })?.status === 0) {
    return 'Le service des réservations est injoignable. Réessayez dans un instant.';
  }
  const details = (body?.details ?? [])
    .map((d) => d.message)
    .filter((m) => m && m !== body?.message);
  return details.length ? `${errorMessage(err)} (${details.join(' ; ')})` : errorMessage(err);
}

/** Erreurs par champ (400), pour les afficher sous les champs du formulaire. */
export function fieldErrors(err: unknown): Record<string, string> {
  const body = (err as { error?: Partial<ApiError> })?.error;
  const result: Record<string, string> = {};
  for (const d of body?.details ?? []) {
    result[d.field] ??= d.message;
  }
  return result;
}
