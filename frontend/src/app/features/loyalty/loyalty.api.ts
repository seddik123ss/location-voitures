import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { Page } from '../../core/api';
import { DiscountPreview, LoyaltyAccount, PointTransaction, PromoCode, PromoCodeRequest, Tier } from './loyalty.model';

/** Appels à loyalty-service via la Gateway (/api/loyalty/**, /api/promo-codes/**). */
@Injectable({ providedIn: 'root' })
export class LoyaltyApi {
  private readonly http = inject(HttpClient);

  // ---------------------------------------------------------------- client
  me(): Observable<LoyaltyAccount> {
    return this.http.get<LoyaltyAccount>('/api/loyalty/me');
  }

  myTransactions(page: number, size: number): Observable<Page<PointTransaction>> {
    const params = new HttpParams().set('page', page).set('size', size);
    return this.http.get<Page<PointTransaction>>('/api/loyalty/me/transactions', { params });
  }

  redeem(points: number): Observable<PromoCode> {
    return this.http.post<PromoCode>('/api/loyalty/me/redeem', { points });
  }

  myVouchers(): Observable<PromoCode[]> {
    return this.http.get<PromoCode[]>('/api/loyalty/me/vouchers');
  }

  /** Aperçu d'un code (rien n'est consommé) : utilisé par le composant promo-code-input. */
  check(code: string, amount: number): Observable<DiscountPreview> {
    return this.http.post<DiscountPreview>('/api/promo-codes/check', { code, amount });
  }

  // ---------------------------------------------------------------- AGENT, ADMIN
  accounts(filter: { tier?: Tier | ''; page: number; size: number }): Observable<Page<LoyaltyAccount>> {
    let params = new HttpParams().set('page', filter.page).set('size', filter.size);
    if (filter.tier) params = params.set('tier', filter.tier);
    return this.http.get<Page<LoyaltyAccount>>('/api/loyalty/accounts', { params });
  }

  account(userId: string): Observable<LoyaltyAccount> {
    return this.http.get<LoyaltyAccount>(`/api/loyalty/accounts/${userId}`);
  }

  adjust(userId: string, points: number, reason: string): Observable<LoyaltyAccount> {
    return this.http.post<LoyaltyAccount>(`/api/loyalty/accounts/${userId}/adjust`, { points, reason });
  }

  promoCodes(filter: { active?: boolean | null; page: number; size: number }): Observable<Page<PromoCode>> {
    let params = new HttpParams().set('page', filter.page).set('size', filter.size);
    if (filter.active !== null && filter.active !== undefined) params = params.set('active', filter.active);
    return this.http.get<Page<PromoCode>>('/api/promo-codes', { params });
  }

  promoCode(id: string): Observable<PromoCode> {
    return this.http.get<PromoCode>(`/api/promo-codes/${id}`);
  }

  createPromoCode(body: PromoCodeRequest): Observable<PromoCode> {
    return this.http.post<PromoCode>('/api/promo-codes', body);
  }

  updatePromoCode(id: string, body: PromoCodeRequest): Observable<PromoCode> {
    return this.http.put<PromoCode>(`/api/promo-codes/${id}`, body);
  }

  deletePromoCode(id: string): Observable<void> {
    return this.http.delete<void>(`/api/promo-codes/${id}`);
  }
}
