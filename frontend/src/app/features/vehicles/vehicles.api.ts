import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';
import { ApiError, errorMessage, Page } from '../../core/api';
import {
  Agency,
  Category,
  Maintenance,
  MaintenanceInput,
  Vehicle,
  VehicleFilter,
  VehicleInput,
} from './vehicles.models';

const BASE = '/api/vehicles';

/** Appels à vehicle-service, via la Gateway (proxy Angular). */
@Injectable({ providedIn: 'root' })
export class VehiclesApi {
  private readonly http = inject(HttpClient);

  list(filter: VehicleFilter): Observable<Page<Vehicle>> {
    let params = new HttpParams().set('page', filter.page).set('size', filter.size);
    for (const key of ['categoryId', 'agencyId', 'fuel', 'transmission', 'status'] as const) {
      const value = filter[key];
      if (value) {
        params = params.set(key, value);
      }
    }
    if (filter.maxPrice != null && filter.maxPrice > 0) {
      params = params.set('maxPrice', filter.maxPrice);
    }
    return this.http.get<Page<Vehicle>>(BASE, { params });
  }

  get(id: string): Observable<Vehicle> {
    return this.http.get<Vehicle>(`${BASE}/${id}`);
  }

  create(body: VehicleInput): Observable<Vehicle> {
    return this.http.post<Vehicle>(BASE, body);
  }

  update(id: string, body: VehicleInput): Observable<Vehicle> {
    return this.http.put<Vehicle>(`${BASE}/${id}`, body);
  }

  delete(id: string): Observable<void> {
    return this.http.delete<void>(`${BASE}/${id}`);
  }

  categories(): Observable<Category[]> {
    return this.http.get<Page<Category>>('/api/categories', { params: { size: 100 } }).pipe(map((p) => p.items));
  }

  agencies(): Observable<Agency[]> {
    return this.http.get<Page<Agency>>('/api/agencies', { params: { size: 100 } }).pipe(map((p) => p.items));
  }

  maintenances(vehicleId: string): Observable<Maintenance[]> {
    return this.http
      .get<Page<Maintenance>>(`${BASE}/${vehicleId}/maintenances`, { params: { size: 100 } })
      .pipe(map((p) => p.items));
  }

  addMaintenance(vehicleId: string, body: MaintenanceInput): Observable<Maintenance> {
    return this.http.post<Maintenance>(`${BASE}/${vehicleId}/maintenances`, body);
  }

  deleteMaintenance(vehicleId: string, id: string): Observable<void> {
    return this.http.delete<void>(`${BASE}/${vehicleId}/maintenances/${id}`);
  }
}

/** Message à afficher pour une erreur de l'API, avec le détail des champs (400, 409). */
export function vehicleError(err: unknown): string {
  const status = err instanceof HttpErrorResponse ? err.status : 0;
  if (status === 0) {
    return 'Le serveur est injoignable. Vérifiez que la Gateway et vehicle-service tournent, puis réessayez.';
  }
  if (status === 403) {
    return 'Votre rôle ne permet pas cette action (AGENT ou ADMIN requis).';
  }
  const details = (err as { error?: Partial<ApiError> })?.error?.details ?? [];
  const suffix = details.length ? ' : ' + details.map((d) => `${d.field} (${d.message})`).join(', ') : '';
  return errorMessage(err) + suffix;
}