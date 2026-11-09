import { DatePipe, DecimalPipe } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { hasRole } from '../../core/keycloak';
import { vehicleError, VehiclesApi } from './vehicles.api';
import { FUEL_LABELS, Maintenance, STATUS_LABELS, TRANSMISSION_LABELS, Vehicle } from './vehicles.models';

/** Détail d'un véhicule. AGENT / ADMIN : modifier, supprimer, gérer les maintenances. */
@Component({
  selector: 'app-vehicle-detail',
  imports: [RouterLink, DecimalPipe, DatePipe, ReactiveFormsModule],
  styleUrl: './vehicles.scss',
  template: `
    <a class="back" routerLink="/vehicles">← Retour au catalogue</a>

    @if (notice()) {
      <div class="alert ok">{{ notice() }}</div>
    }
    @if (error()) {
      <div class="alert">{{ error() }}</div>
    }

    @if (vehicle(); as v) {
      <div class="head">
        <div>
          <h1>
            {{ v.brand }} {{ v.model }}
            <span class="badge s-{{ v.status }}">{{ statusLabels[v.status] }}</span>
          </h1>
          <p class="sub">{{ v.category.name }} · {{ v.agency.name }}, {{ v.agency.address }}</p>
        </div>
        @if (staff) {
          <div class="actions">
            <a class="btn" routerLink="edit">Modifier</a>
            <button class="btn danger" type="button" [disabled]="busy()" (click)="remove()">Supprimer</button>
          </div>
        }
      </div>

      <div class="card">
        <dl class="specs">
          <div><dt>Prix par jour</dt><dd>{{ v.category.dailyRate | number: '1.3-3' }} TND</dd></div>
          <div><dt>Caution</dt><dd>{{ v.category.deposit | number: '1.3-3' }} TND</dd></div>
          <div><dt>Année</dt><dd>{{ v.year }}</dd></div>
          <div><dt>Carburant</dt><dd>{{ fuelLabels[v.fuel] }}</dd></div>
          <div><dt>Boîte</dt><dd>{{ transmissionLabels[v.transmission] }}</dd></div>
          <div><dt>Places</dt><dd>{{ v.seats }}</dd></div>
          <div><dt>Kilométrage</dt><dd>{{ v.mileage | number }} km</dd></div>
          <div><dt>Immatriculation</dt><dd class="mono">{{ v.plate }}</dd></div>
        </dl>
      </div>

      @if (staff) {
        <div class="card">
          <h2>Maintenances</h2>
          @if (mError()) {
            <div class="alert">{{ mError() }}</div>
          }
          @if (maintenances().length === 0) {
            <p class="sub">Aucune maintenance enregistrée.</p>
          } @else {
            <table>
              <thead>
                <tr><th>Date</th><th>Type</th><th>Coût</th><th></th></tr>
              </thead>
              <tbody>
                @for (m of maintenances(); track m.id) {
                  <tr>
                    <td>{{ m.date | date: 'dd/MM/yyyy' }}</td>
                    <td>{{ m.type }}</td>
                    <td>{{ m.cost | number: '1.3-3' }} TND</td>
                    <td><button class="btn danger small" type="button" [disabled]="busy()" (click)="deleteMaintenance(m)">Supprimer</button></td>
                  </tr>
                }
              </tbody>
            </table>
          }
          <form class="filters" [formGroup]="mForm" (ngSubmit)="addMaintenance()">
            <label>Type <input formControlName="type" placeholder="ex. Vidange" /></label>
            <label>Date <input type="date" formControlName="date" /></label>
            <label>Coût (TND) <input type="number" min="0" step="0.001" formControlName="cost" /></label>
            <div class="actions">
              <button class="btn primary" type="submit" [disabled]="mForm.invalid || busy()">Ajouter</button>
            </div>
          </form>
        </div>
      }
    } @else if (!error()) {
      <p class="sub">Chargement…</p>
    }
  `,
})
export class VehicleDetail {
  private readonly api = inject(VehiclesApi);
  private readonly router = inject(Router);
  private readonly id = inject(ActivatedRoute).snapshot.paramMap.get('id')!;

  protected readonly staff = hasRole('AGENT', 'ADMIN');
  protected readonly fuelLabels = FUEL_LABELS;
  protected readonly transmissionLabels = TRANSMISSION_LABELS;
  protected readonly statusLabels = STATUS_LABELS;

  protected readonly vehicle = signal<Vehicle | null>(null);
  protected readonly maintenances = signal<Maintenance[]>([]);
  protected readonly error = signal('');
  protected readonly mError = signal('');
  protected readonly busy = signal(false);
  protected readonly notice = signal((history.state as { flash?: string } | null)?.flash ?? '');

  protected readonly mForm = inject(FormBuilder).nonNullable.group({
    type: ['', [Validators.required, Validators.maxLength(100)]],
    date: [new Date().toISOString().slice(0, 10), Validators.required],
    cost: [0, [Validators.required, Validators.min(0)]],
  });

  constructor() {
    this.api.get(this.id).subscribe({
      next: (v) => this.vehicle.set(v),
      error: (err) => this.error.set(vehicleError(err)),
    });
    if (this.staff) {
      this.api.maintenances(this.id).subscribe({
        next: (list) => this.maintenances.set(list),
        error: (err) => this.mError.set(vehicleError(err)),
      });
    }
  }

  protected remove(): void {
    if (!confirm('Supprimer définitivement ce véhicule ?')) {
      return;
    }
    this.busy.set(true);
    this.api.delete(this.id).subscribe({
      next: () => this.router.navigate(['/vehicles'], { state: { flash: 'Véhicule supprimé.' } }),
      error: (err) => {
        this.error.set(vehicleError(err));
        this.busy.set(false);
      },
    });
  }

  protected addMaintenance(): void {
    if (this.mForm.invalid) {
      return;
    }
    this.busy.set(true);
    this.mError.set('');
    this.api.addMaintenance(this.id, this.mForm.getRawValue()).subscribe({
      next: (m) => {
        this.maintenances.update((list) => [m, ...list]);
        this.mForm.reset();
        this.busy.set(false);
      },
      error: (err) => {
        this.mError.set(vehicleError(err));
        this.busy.set(false);
      },
    });
  }

  protected deleteMaintenance(m: Maintenance): void {
    if (!confirm(`Supprimer la maintenance « ${m.type} » ?`)) {
      return;
    }
    this.busy.set(true);
    this.api.deleteMaintenance(this.id, m.id).subscribe({
      next: () => {
        this.maintenances.update((list) => list.filter((x) => x.id !== m.id));
        this.busy.set(false);
      },
      error: (err) => {
        this.mError.set(vehicleError(err));
        this.busy.set(false);
      },
    });
  }
}