import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { Page } from '../../core/api';
import {
  Address,
  AddressRequest,
  CreateLicenseRequest,
  CreateUserRequest,
  License,
  LicenseRequest,
  Reservation,
  UpdateUserRequest,
  UserProfile,
  UserRole,
  UserSummary,
} from './users.model';

/** Appels à user-service via la Gateway (/api/users/**, /api/licenses/**). */
@Injectable({ providedIn: 'root' })
export class UsersApi {
  private readonly http = inject(HttpClient);

  list(filter: { role?: UserRole | ''; name?: string; page: number; size: number }): Observable<Page<UserSummary>> {
    let params = new HttpParams().set('page', filter.page).set('size', filter.size);
    if (filter.role) params = params.set('role', filter.role);
    if (filter.name?.trim()) params = params.set('name', filter.name.trim());
    return this.http.get<Page<UserSummary>>('/api/users', { params });
  }

  me(): Observable<UserProfile> {
    return this.http.get<UserProfile>('/api/users/me');
  }

  get(id: string): Observable<UserProfile> {
    return this.http.get<UserProfile>(`/api/users/${id}`);
  }

  create(body: CreateUserRequest): Observable<UserProfile> {
    return this.http.post<UserProfile>('/api/users', body);
  }

  update(id: string, body: UpdateUserRequest): Observable<UserProfile> {
    return this.http.put<UserProfile>(`/api/users/${id}`, body);
  }

  delete(id: string): Observable<void> {
    return this.http.delete<void>(`/api/users/${id}`);
  }

  reservations(id: string, page: number, size: number): Observable<Page<Reservation>> {
    const params = new HttpParams().set('page', page).set('size', size);
    return this.http.get<Page<Reservation>>(`/api/users/${id}/reservations`, { params });
  }

  addAddress(userId: string, body: AddressRequest): Observable<Address> {
    return this.http.post<Address>(`/api/users/${userId}/addresses`, body);
  }

  updateAddress(userId: string, id: string, body: AddressRequest): Observable<Address> {
    return this.http.put<Address>(`/api/users/${userId}/addresses/${id}`, body);
  }

  deleteAddress(userId: string, id: string): Observable<void> {
    return this.http.delete<void>(`/api/users/${userId}/addresses/${id}`);
  }

  createLicense(body: CreateLicenseRequest): Observable<License> {
    return this.http.post<License>('/api/licenses', body);
  }

  updateLicense(id: string, body: LicenseRequest): Observable<License> {
    return this.http.put<License>(`/api/licenses/${id}`, body);
  }

  verifyLicense(id: string): Observable<License> {
    return this.http.patch<License>(`/api/licenses/${id}/verify`, {});
  }

  deleteLicense(id: string): Observable<void> {
    return this.http.delete<void>(`/api/licenses/${id}`);
  }
}
