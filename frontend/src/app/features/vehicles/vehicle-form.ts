import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { vehicleError, VehiclesApi } from './vehicles.api';
import {
  Agency,
  Category,
  Fuel,
  FUEL_LABELS,
  FUELS,
  STATUS_LABELS,
  STATUSES,
  Transmission,
  TRANSMISSION_LABELS,
  TRANSMISSIONS,
  VehicleInput,
  VehicleStatus,
} from './vehicles.models';

/** Ajout (/vehicles/new) ou modification (/vehicles/:id/edit) d'un véhicule — AGENT / ADMIN. */
@Component({
  selector: 'app-vehicle-form',
  imports: [ReactiveFormsModule, RouterLink],
  styleUrl: './vehicles.scss',
  template: `
    <a class="back" [routerLink]="id ? ['/vehicles', id] : ['/vehicles']">← Retour</a>
    <h1>{{ id ? 'Modifier le véhicule' : 'Nouveau véhicule' }}</h1>
    <p class="sub">Tous les champs sont obligatoires.</p>

    @if (error()) {
      <div class="alert">{{ error() }}</div>
    }

    <form class="card" [formGroup]="form" (ngSubmit)="save()">
      <div class="form-grid">
        <label>Immatriculation <input formControlName="plate" placeholder="ex. 129 TU 4507" /></label>
        <label>Marque <input formControlName="brand" /></label>
        <label>Modèle <input formControlName="model" /></label>
        <label>Année <input type="number" formControlName="year" /></label>
        <label>
          Carburant
          <select formControlName="fuel">
            @for (f of fuels; track f) {
              <option [value]="f">{{ fuelLabels[f] }}</option>
            }
          </select>
        </label>
        <label>
          Boîte
          <select formControlName="transmission">
            @for (t of transmissions; track t) {
              <option [value]="t">{{ transmissionLabels[t] }}</option>
            }
          </select>
        </label>
        <label>Places <input type="number" min="1" max="9" formControlName="seats" /></label>
        <label>Kilométrage <input type="number" min="0" formControlName="mileage" /></label>
        <label>
          Statut
          <select formControlName="status">
            @for (s of statuses; track s) {
              <option [value]="s">{{ statusLabels[s] }}</option>
            }
          </select>
        </label>
        <label>
          Catégorie
          <select formControlName="categoryId">
            <option value="" disabled>Choisir…</option>
            @for (c of categories(); track c.id) {
              <option [value]="c.id">{{ c.name }} ({{ c.dailyRate }} TND / jour)</option>
            }
          </select>
        </label>
        <label>
          Agence
          <select formControlName="agencyId">
            <option value="" disabled>Choisir…</option>
            @for (a of agencies(); track a.id) {
              <option [value]="a.id">{{ a.name }}</option>
            }
          </select>
        </label>
      </div>

      @if (form.invalid && form.touched) {
        <p class="invalid">Vérifiez les champs : tous sont obligatoires, places entre 1 et 9, année à partir de 1990.</p>
      }

      <div class="actions">
        <button class="btn primary" type="submit" [disabled]="saving()">{{ id ? 'Enregistrer' : 'Ajouter' }}</button>
        <a class="btn" [routerLink]="id ? ['/vehicles', id] : ['/vehicles']">Annuler</a>
      </div>
    </form>
  `,
})
export class VehicleForm {
  private readonly api = inject(VehiclesApi);
  private readonly router = inject(Router);
  protected readonly id = inject(ActivatedRoute).snapshot.paramMap.get('id');

  protected readonly fuels = FUELS;
  protected readonly transmissions = TRANSMISSIONS;
  protected readonly statuses = STATUSES;
  protected readonly fuelLabels = FUEL_LABELS;
  protected readonly transmissionLabels = TRANSMISSION_LABELS;
  protected readonly statusLabels = STATUS_LABELS;

  protected readonly categories = signal<Category[]>([]);
  protected readonly agencies = signal<Agency[]>([]);
  protected readonly error = signal('');
  protected readonly saving = signal(false);

  protected readonly form = inject(FormBuilder).nonNullable.group({
    plate: ['', [Validators.required, Validators.maxLength(20)]],
    brand: ['', [Validators.required, Validators.maxLength(50)]],
    model: ['', [Validators.required, Validators.maxLength(50)]],
    year: [new Date().getFullYear(), [Validators.required, Validators.min(1990)]],
    fuel: ['DIESEL' as Fuel, Validators.required],
    transmission: ['MANUAL' as Transmission, Validators.required],
    seats: [5, [Validators.required, Validators.min(1), Validators.max(9)]],
    mileage: [0, [Validators.required, Validators.min(0)]],
    status: ['AVAILABLE' as VehicleStatus, Validators.required],
    categoryId: ['', Validators.required],
    agencyId: ['', Validators.required],
  });

  constructor() {
    this.api.categories().subscribe({ next: (c) => this.categories.set(c), error: (err) => this.error.set(vehicleError(err)) });
    this.api.agencies().subscribe({ next: (a) => this.agencies.set(a), error: (err) => this.error.set(vehicleError(err)) });
    if (this.id) {
      this.api.get(this.id).subscribe({
        next: (v) => this.form.patchValue(v),
        error: (err) => this.error.set(vehicleError(err)),
      });
    }
  }

  protected save(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    this.saving.set(true);
    this.error.set('');
    const body = this.form.getRawValue() as VehicleInput;
    const request = this.id ? this.api.update(this.id, body) : this.api.create(body);
    request.subscribe({
      next: (v) =>
        this.router.navigate(['/vehicles', v.id], {
          state: { flash: this.id ? 'Véhicule modifié.' : 'Véhicule ajouté.' },
        }),
      error: (err) => {
        this.error.set(vehicleError(err));
        this.saving.set(false);
      },
    });
  }
}