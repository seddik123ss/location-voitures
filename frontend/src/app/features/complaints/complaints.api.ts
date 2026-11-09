import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';
import { ApiError, errorMessage, Page } from '../../core/api';
import {
  ComplaintDetail,
  ComplaintFilter,
  ComplaintMessage,
  ComplaintPriority,
  ComplaintStatus,
  ComplaintSummary,
  CreateComplaint,
  ReservationOption,
  UpdateComplaint,
} from './complaints.models';

const BASE = '/api/complaints';

/** Appels à complaint-service, via la Gateway (proxy Angular). */
@Injectable({ providedIn: 'root' })
export class ComplaintsApi {
  private readonly http = inject(HttpClient);

  list(filter: ComplaintFilter): Observable<Page<ComplaintSummary>> {
    let params = new HttpParams().set('page', filter.page).set('size', filter.size);
    for (const key of ['status', 'category', 'priority', 'userId'] as const) {
      const value = filter[key]?.trim();
      if (value) {
        params = params.set(key, value);
      }
    }
    return this.http.get<Page<ComplaintSummary>>(BASE, { params });
  }

  get(id: string): Observable<ComplaintDetail> {
    return this.http.get<ComplaintDetail>(`${BASE}/${id}`);
  }

  create(body: CreateComplaint): Observable<ComplaintDetail> {
    return this.http.post<ComplaintDetail>(BASE, body);
  }

  update(id: string, body: UpdateComplaint): Observable<ComplaintDetail> {
    return this.http.put<ComplaintDetail>(`${BASE}/${id}`, body);
  }

  delete(id: string): Observable<void> {
    return this.http.delete<void>(`${BASE}/${id}`);
  }

  changeStatus(id: string, status: ComplaintStatus, comment: string | null): Observable<ComplaintDetail> {
    return this.http.patch<ComplaintDetail>(`${BASE}/${id}/status`, { status, comment });
  }

  changePriority(id: string, priority: ComplaintPriority): Observable<ComplaintDetail> {
    return this.http.patch<ComplaintDetail>(`${BASE}/${id}/priority`, { priority });
  }

  addMessage(id: string, content: string): Observable<ComplaintMessage> {
    return this.http.post<ComplaintMessage>(`${BASE}/${id}/messages`, { content });
  }

  /**
   * Réservations du client connecté (reservation-service, Aziz). Accepte une page {items...} ou une liste.
   * Tant que la route n'existe pas, l'appel échoue et le formulaire propose un champ UUID.
   */
  myReservations(): Observable<ReservationOption[]> {
    return this.http
      .get<Page<ReservationOption> | ReservationOption[]>('/api/reservations', { params: { size: 100 } })
      .pipe(
        map((body) => {
          const items = Array.isArray(body) ? body : body?.items;
          if (!Array.isArray(items)) {
            throw new Error('Réponse inattendue de /api/reservations');
          }
          return items.filter((r) => typeof r?.id === 'string');
        }),
      );
  }
}

/**
 * Message à afficher pour une erreur de l'API : errorMessage() du socle, avec le détail des champs (400)
 * et des messages clairs pour 403 / 404 sur une réclamation.
 */
export function complaintError(err: unknown, context: 'load' | 'action' = 'action'): string {
  const status = err instanceof HttpErrorResponse ? err.status : 0;
  if (status === 0) {
    return 'Le serveur est injoignable. Vérifiez que la Gateway et complaint-service tournent, puis réessayez.';
  }
  if (status === 404 && context === 'load') {
    return "Cette réclamation n'existe pas ou a été supprimée.";
  }
  if (status === 403) {
    const message = errorMessage(err);
    return context === 'load' || message === 'Accès refusé'
      ? "Vous n'avez pas accès à cette réclamation : elle appartient à un autre client ou votre rôle ne le permet pas."
      : message;
  }
  const details = (err as { error?: Partial<ApiError> })?.error?.details ?? [];
  const suffix = details.length ? ' : ' + details.map((d) => `${d.field} (${d.message})`).join(', ') : '';
  return errorMessage(err) + suffix;
}
