import { DecimalPipe } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Page } from '../../core/api';
import { hasRole } from '../../core/keycloak';
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
  Vehicle,
  VehicleStatus,
} from './vehicles.models';

/**
 * Page d'accueil : catalogue des véhicules avec filtres et pagination.
 * AGENT / ADMIN : filtre sur le statut, immatriculation visible, bouton « Ajouter un véhicule ».
 */
@Component({
  selector: 'app-vehicle-list',
  imports: [FormsModule, RouterLink, DecimalPipe],
  styleUrl: './vehicles.scss',
  template: `
    <div class="head">
      <div>
        <h1>{{ staff ? 'Flotte de véhicules' : 'Nos véhicules' }}</h1>
        <p class="sub">
          {{ staff ? 'Gérez les véhicules, leur statut et leurs maintenances.' : 'Choisissez un véhicule dans l\\'une de nos agences.' }}
        </p>
      </div>
      @if (staff) {
        <a class="btn primary" routerLink="new">Ajouter un véhicule</a>
      }
    </div>

    <form class="card filters" (ngSubmit)="search()">
      <label>
        Catégorie
        <select name="categoryId" [ngModel]="categoryId()" (ngModelChange)="categoryId.set($event)">
          <option value="">Toutes</option>
          @for (c of categories(); track c.id) {
            <option [value]="c.id">{{ c.name }}</option>
          }
        </select>
      </label>
      <label>
        Agence
        <select name="agencyId" [ngModel]="agencyId()" (ngModelChange)="agencyId.set($event)">
          <option value="">Toutes</option>
          @for (a of agencies(); track a.id) {
            <option [value]="a.id">{{ a.city }}</option>
          }
        </select>
      </label>
      <label>
        Carburant
        <select name="fuel" [ngModel]="fuel()" (ngModelChange)="fuel.set($event)">
          <option value="">Tous</option>
          @for (f of fuels; track f) {
            <option [value]="f">{{ fuelLabels[f] }}</option>
          }
        </select>
      </label>
      <label>
        Boîte
        <select name="transmission" [ngModel]="transmission()" (ngModelChange)="transmission.set($event)">
          <option value="">Toutes</option>
          @for (t of transmissions; track t) {
            <option [value]="t">{{ transmissionLabels[t] }}</option>
          }
        </select>
      </label>
      @if (staff) {
        <label>
          Statut
          <select name="status" [ngModel]="status()" (ngModelChange)="status.set($event)">
            <option value="">Tous</option>
            @for (s of statuses; track s) {
              <option [value]="s">{{ statusLabels[s] }}</option>
            }
          </select>
        </label>
      }
      <label>
        Prix max / jour (TND)
        <input type="number" min="0" name="maxPrice" [ngModel]="maxPrice()" (ngModelChange)="maxPrice.set($event)" />
      </label>
      <div class="actions">
        <button class="btn primary" type="submit">Filtrer</button>
        <button class="btn" type="button" (click)="reset()">Effacer</button>
      </div>
    </form>

    @if (notice()) {
      <div class="alert ok">{{ notice() }}</div>
    }
    @if (error()) {
      <div class="alert">{{ error() }}</div>
    }

    @if (result(); as r) {
      @if (r.items.length === 0) {
        <div class="card empty">Aucun véhicule ne correspond à ces filtres.</div>
      } @else {
        <div class="grid">
          @for (v of r.items; track v.id) {
            <a class="vcard" [routerLink]="v.id">
              <div class="title">{{ v.brand }} {{ v.model }}</div>
              <div class="meta">{{ v.year }} · {{ fuelLabels[v.fuel] }} · {{ transmissionLabels[v.transmission] }} · {{ v.seats }} places</div>
              <div class="meta">{{ v.category.name }} · Agence {{ v.agency.city }}</div>
              @if (staff) {
                <div class="meta mono">{{ v.plate }}</div>
              }
              <div class="row">
                <span class="price">{{ v.category.dailyRate | number: '1.3-3' }} <small>TND / jour</small></span>
                <span class="badge s-{{ v.status }}">{{ statusLabels[v.status] }}</span>
              </div>
            </a>
          }
        </div>
        <div class="pager">
          <span>{{ r.total }} véhicule{{ r.total > 1 ? 's' : '' }} · page {{ r.page + 1 }} / {{ pageCount() }}</span>
          <button class="btn" type="button" [disabled]="r.page === 0 || loading()" (click)="goTo(r.page - 1)">Précédente</button>
          <button class="btn" type="button" [disabled]="r.page + 1 >= pageCount() || loading()" (click)="goTo(r.page + 1)">Suivante</button>
        </div>
      }
    } @else if (loading()) {
      <p class="sub">Chargement…</p>
    }
  `,
})
export class VehicleList {
  private readonly api = inject(VehiclesApi);

  protected readonly staff = hasRole('AGENT', 'ADMIN');
  protected readonly fuels = FUELS;
  protected readonly transmissions = TRANSMISSIONS;
  protected readonly statuses = STATUSES;
  protected readonly fuelLabels = FUEL_LABELS;
  protected readonly transmissionLabels = TRANSMISSION_LABELS;
  protected readonly statusLabels = STATUS_LABELS;

  protected readonly categories = signal<Category[]>([]);
  protected readonly agencies = signal<Agency[]>([]);

  protected readonly categoryId = signal('');
  protected readonly agencyId = signal('');
  protected readonly fuel = signal<Fuel | ''>('');
  protected readonly transmission = signal<Transmission | ''>('');
  protected readonly status = signal<VehicleStatus | ''>('');
  protected readonly maxPrice = signal<number | null>(null);
  protected readonly page = signal(0);
  protected readonly size = 12;

  protected readonly result = signal<Page<Vehicle> | null>(null);
  protected readonly loading = signal(false);
  protected readonly error = signal('');
  /** Message laissé par la page précédente (ex. après une suppression). */
  protected readonly notice = signal((history.state as { flash?: string } | null)?.flash ?? '');

  protected readonly pageCount = computed(() => {
    const r = this.result();
    return r ? Math.max(1, Math.ceil(r.total / r.size)) : 1;
  });

  constructor() {
    this.api.categories().subscribe({ next: (c) => this.categories.set(c), error: () => {} });
    this.api.agencies().subscribe({ next: (a) => this.agencies.set(a), error: () => {} });
    this.load();
  }

  protected search(): void {
    this.page.set(0);
    this.load();
  }

  protected reset(): void {
    this.categoryId.set('');
    this.agencyId.set('');
    this.fuel.set('');
    this.transmission.set('');
    this.status.set('');
    this.maxPrice.set(null);
    this.search();
  }

  protected goTo(page: number): void {
    this.page.set(page);
    this.load();
  }

  private load(): void {
    this.loading.set(true);
    this.error.set('');
    this.api
      .list({
        page: this.page(),
        size: this.size,
        categoryId: this.categoryId(),
        agencyId: this.agencyId(),
        fuel: this.fuel(),
        transmission: this.transmission(),
        status: this.staff ? this.status() : '',
        maxPrice: this.maxPrice(),
      })
      .subscribe({
        next: (page) => {
          this.result.set(page);
          this.loading.set(false);
        },
        error: (err) => {
          this.error.set(vehicleError(err));
          this.result.set(null);
          this.loading.set(false);
        },
      });
  }
}