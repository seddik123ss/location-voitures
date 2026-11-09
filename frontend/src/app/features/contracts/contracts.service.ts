import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { Page } from '../../core/api';

/** Modèles de contract-service (docs/openapi/contract-service.yaml). */
export type ContractStatus = 'ACTIVE' | 'CLOSED';
export type InspectionType = 'PICKUP' | 'RETURN';
export type ExtraFeeType = 'LATE' | 'FUEL' | 'MILEAGE' | 'DAMAGE';

export interface Inspection {
  id: string;
  contractId: string;
  type: InspectionType;
  mileage: number;
  fuelLevel: number;
  damages: string | null;
  photos: string[];
  inspectedAt: string;
}

export interface ExtraFee {
  id: string;
  contractId: string;
  type: ExtraFeeType;
  amount: number;
}

export interface Contract {
  id: string;
  number: string;
  reservationId: string;
  userId: string;
  vehicleId: string;
  signedAt: string;
  status: ContractStatus;
  closedAt: string | null;
  inspections: Inspection[];
  extraFees: ExtraFee[];
  totalExtraFees: number;
}

export interface HandoverRequest {
  reservationId: string;
  mileage: number;
  fuelLevel: number;
  damages?: string | null;
  photos?: string[];
}

export interface ReturnRequest {
  mileage: number;
  fuelLevel: number;
  damages?: string | null;
  damageFee?: number;
  photos?: string[];
  returnedAt?: string;
}

export interface ReturnSettlement {
  contract: Contract;
  extraFees: ExtraFee[];
  totalExtraFees: number;
  depositToRelease: number;
  currency: string;
}

export const FEE_LABELS: Record<ExtraFeeType, string> = {
  LATE: 'Retard',
  FUEL: 'Carburant manquant',
  MILEAGE: 'Kilomètres en plus',
  DAMAGE: 'Dommages',
};

/** Appels à contract-service, toujours via la Gateway (/api/...). */
@Injectable({ providedIn: 'root' })
export class ContractsService {
  private readonly http = inject(HttpClient);

  list(page = 0, size = 10, status?: ContractStatus): Observable<Page<Contract>> {
    let params = new HttpParams().set('page', page).set('size', size);
    if (status) params = params.set('status', status);
    return this.http.get<Page<Contract>>('/api/contracts', { params });
  }

  get(id: string): Observable<Contract> {
    return this.http.get<Contract>(`/api/contracts/${id}`);
  }

  handover(body: HandoverRequest): Observable<Contract> {
    return this.http.post<Contract>('/api/contracts', body);
  }

  returnVehicle(id: string, body: ReturnRequest): Observable<ReturnSettlement> {
    return this.http.post<ReturnSettlement>(`/api/contracts/${id}/return`, body);
  }

  delete(id: string): Observable<void> {
    return this.http.delete<void>(`/api/contracts/${id}`);
  }
}
