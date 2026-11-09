import { DecimalPipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import {
  AbstractControl,
  FormControl,
  FormGroup,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import { ApiError, errorMessage } from '../../core/api';
import { MlApi, PricePrediction, PricePredictionRequest } from './ml-api';
import { AGENCY_OPTIONS, CATEGORY_OPTIONS } from './ml-catalog';
import { MlNav } from './ml-nav';

/** Noms des champs du formulaire = noms des champs JSON renvoyés dans details[].field. */
type SimulatorField = 'categoryId' | 'agencyId' | 'startDate' | 'endDate' | 'occupancyRate';
const SIMULATOR_FIELDS: SimulatorField[] = [
  'categoryId',
  'agencyId',
  'startDate',
  'endDate',
  'occupancyRate',
];

/** Date au format AAAA-MM-JJ, dans N jours (valeurs proposées par défaut). */
function isoDateInDays(days: number): string {
  const date = new Date();
  date.setDate(date.getDate() + days);
  return date.toISOString().slice(0, 10);
}

/** Simulateur : catégorie, agence, dates -> prix journalier conseillé et intervalle. */
@Component({
  selector: 'app-price-simulator',
  imports: [MlNav, ReactiveFormsModule, DecimalPipe],
  templateUrl: './price-simulator.html',
  styleUrl: './ml-pages.scss',
})
export class PriceSimulator {
  private readonly mlApi = inject(MlApi);

  protected readonly categories = CATEGORY_OPTIONS;
  protected readonly agencies = AGENCY_OPTIONS;

  protected readonly form = new FormGroup({
    categoryId: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
    agencyId: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
    startDate: new FormControl(isoDateInDays(14), {
      nonNullable: true,
      validators: [Validators.required],
    }),
    endDate: new FormControl(isoDateInDays(18), {
      nonNullable: true,
      validators: [Validators.required],
    }),
    occupancyRate: new FormControl<number | null>(null),
  });

  protected readonly submitting = signal(false);
  protected readonly prediction = signal<PricePrediction | null>(null);
  protected readonly durationDays = signal(0);
  /** Erreurs renvoyées par le ml-service (422), rangées par champ. */
  protected readonly serverErrors = signal<Partial<Record<SimulatorField, string>>>({});
  protected readonly generalError = signal('');

  constructor() {
    // Dès que l'utilisateur corrige un champ, on efface l'erreur serveur de ce champ
    const destroyRef = inject(DestroyRef);
    for (const field of SIMULATOR_FIELDS) {
      // AbstractControl = type commun à tous les champs (texte ou nombre)
      const control: AbstractControl = this.form.controls[field];
      control.valueChanges
        .pipe(takeUntilDestroyed(destroyRef))
        .subscribe(() => this.serverErrors.update((errors) => ({ ...errors, [field]: undefined })));
    }
  }

  /** Message à afficher sous un champ : erreur du serveur, sinon champ obligatoire non rempli. */
  protected fieldError(field: SimulatorField): string {
    const serverError = this.serverErrors()[field];
    if (serverError) {
      return serverError;
    }
    const control = this.form.controls[field];
    return control.touched && control.hasError('required') ? 'Champ obligatoire' : '';
  }

  protected submit(): void {
    this.form.markAllAsTouched();
    if (this.form.invalid) {
      return;
    }
    // Les règles métier (dates, durée, occupation...) sont vérifiées par le ml-service :
    // une seule source de vérité, et ses erreurs 422 s'affichent sous les bons champs.
    const formValue = this.form.getRawValue();
    const request: PricePredictionRequest = {
      categoryId: formValue.categoryId,
      agencyId: formValue.agencyId,
      startDate: formValue.startDate,
      endDate: formValue.endDate,
    };
    if (formValue.occupancyRate !== null) {
      request.occupancyRate = formValue.occupancyRate;
    }

    this.submitting.set(true);
    this.prediction.set(null);
    this.serverErrors.set({});
    this.generalError.set('');

    this.mlApi.predictPrice(request).subscribe({
      next: (prediction) => {
        this.prediction.set(prediction);
        this.durationDays.set(this.daysBetween(request.startDate, request.endDate));
        this.submitting.set(false);
      },
      error: (error: HttpErrorResponse) => {
        this.showServerErrors(error);
        this.submitting.set(false);
      },
    });
  }

  /** 422 : chaque détail va sous son champ ; les autres erreurs (503...) vont en bandeau. */
  private showServerErrors(error: HttpErrorResponse): void {
    const apiError = error.error as Partial<ApiError> | null;
    const fieldErrors: Partial<Record<SimulatorField, string>> = {};
    const otherMessages: string[] = [];

    for (const detail of apiError?.details ?? []) {
      if ((SIMULATOR_FIELDS as string[]).includes(detail.field)) {
        fieldErrors[detail.field as SimulatorField] = detail.message;
      } else {
        otherMessages.push(`${detail.field} : ${detail.message}`);
      }
    }
    this.serverErrors.set(fieldErrors);
    this.generalError.set([errorMessage(error), ...otherMessages].join(' — '));
  }

  private daysBetween(startDate: string, endDate: string): number {
    const millisecondsPerDay = 24 * 60 * 60 * 1000;
    return Math.round((Date.parse(endDate) - Date.parse(startDate)) / millisecondsPerDay);
  }
}
