import { CurrencyPipe, DatePipe } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Page } from '../../core/api';
import { hasRole } from '../../core/keycloak';
import { reservationError, ReservationsApi } from './reservations.api';
import {
  agencyName,
  Reservation,
  ReservationStatus,
  STATUS_LABELS,
  STATUSES,
  UUID_PATTERN,
  vehicleLabel,
} from './reservations.models';

/**
 * CLIENT : « Mes réservations » (l'API ne renvoie que les siennes), filtre par statut.
 * AGENT / ADMIN : toutes les réservations, filtres statut, client et véhicule.
 */
@Component({
  selector: 'app-reservation-list',
  imports: [FormsModule, RouterLink, DatePipe, CurrencyPipe],
  templateUrl: './reservation-list.html',
  styleUrl: './reservations.scss',
})
export class ReservationList {
  private readonly api = inject(ReservationsApi);

  protected readonly staff = hasRole('AGENT', 'ADMIN');
  protected readonly statuses = STATUSES;
  protected readonly statusLabels = STATUS_LABELS;
  protected readonly vehicleLabel = vehicleLabel;
  protected readonly agencyName = agencyName;

  protected readonly status = signal<ReservationStatus | ''>('');
  protected readonly userId = signal('');
  protected readonly vehicleId = signal('');
  protected readonly page = signal(0);
  protected readonly size = 10;

  protected readonly result = signal<Page<Reservation> | null>(null);
  protected readonly loading = signal(false);
  protected readonly error = signal('');
  /** Message laissé par la page précédente (ex. après une suppression). */
  protected readonly notice = signal((history.state as { flash?: string } | null)?.flash ?? '');

  protected readonly invalidIds = computed(() =>
    [this.userId(), this.vehicleId()].some((v) => v.trim() !== '' && !UUID_PATTERN.test(v.trim())),
  );
  protected readonly pageCount = computed(() => {
    const r = this.result();
    return r ? Math.max(1, Math.ceil(r.total / r.size)) : 1;
  });

  constructor() {
    this.load();
  }

  protected search(): void {
    this.page.set(0);
    this.load();
  }

  protected reset(): void {
    this.status.set('');
    this.userId.set('');
    this.vehicleId.set('');
    this.search();
  }

  protected goTo(page: number): void {
    this.page.set(page);
    this.load();
  }

  private load(): void {
    if (this.invalidIds()) {
      return;
    }
    this.loading.set(true);
    this.error.set('');
    this.api
      .list({
        page: this.page(),
        size: this.size,
        status: this.status(),
        userId: this.userId(),
        vehicleId: this.vehicleId(),
      })
      .subscribe({
        next: (page) => {
          this.result.set(page);
          this.loading.set(false);
        },
        error: (err) => {
          this.error.set(reservationError(err));
          this.loading.set(false);
        },
      });
  }
}
