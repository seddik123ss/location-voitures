import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { Page } from '../../core/api';

/** Métriques d'une version, mesurées sur le jeu de test (voir le notebook du ml-service). */
export interface ModelMetrics {
  mae: number;
  r2: number;
  intervalCoverage: number;
  validationMae?: number;
}

/** Une version entraînée du modèle de prix (GET /api/ml/models). */
export interface ModelVersion {
  id: string;
  name: string;
  version: number;
  metrics: ModelMetrics;
  trainedAt: string;
  active: boolean;
}

/** Entrée de POST /api/ml/price-predictions (occupancyRate optionnel, entre 0 et 1). */
export interface PricePredictionRequest {
  categoryId: string;
  agencyId: string;
  startDate: string;
  endDate: string;
  occupancyRate?: number;
}

/** Sortie de POST /api/ml/price-predictions (montants en TND, 3 décimales). */
export interface PricePrediction {
  dailyPrice: number;
  lowerBound: number;
  upperBound: number;
  currency: string;
  modelVersion: number;
}

/** Toujours une URL relative /api/... : le proxy (ou la Gateway) l'envoie au ml-service. */
const ML_API_URL = '/api/ml';

/** Appels HTTP vers le ml-service. Le token Keycloak est ajouté par l'intercepteur commun. */
@Injectable({ providedIn: 'root' })
export class MlApi {
  private readonly http = inject(HttpClient);

  listModels(page: number, size: number): Observable<Page<ModelVersion>> {
    const params = new HttpParams().set('page', page).set('size', size);
    return this.http.get<Page<ModelVersion>>(`${ML_API_URL}/models`, { params });
  }

  activateModel(modelId: string): Observable<ModelVersion> {
    return this.http.post<ModelVersion>(`${ML_API_URL}/models/${modelId}/activate`, null);
  }

  trainNewModel(): Observable<ModelVersion> {
    return this.http.post<ModelVersion>(`${ML_API_URL}/trainings`, null);
  }

  predictPrice(request: PricePredictionRequest): Observable<PricePrediction> {
    return this.http.post<PricePrediction>(`${ML_API_URL}/price-predictions`, request);
  }
}
