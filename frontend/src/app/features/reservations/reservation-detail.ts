import { CurrencyPipe, DatePipe } from '@angular/common';
import { Component, computed, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { currentUserId, hasRole } from '../../core/keycloak';
import { reservationError, ReservationsApi } from './reservations.api';
import {
  agencyName,
  AGENCIES,
  Reservation,
  ReservationStatus,
  STATUS_LABELS,
  today,
  TRANSITION_LABELS,
  vehicleLabel,
} from './reservations.models';

/**
 * Détail d'une réservation avec son historique.
 * Propriétaire, AGENT, ADMIN : annuler (PENDING ou CONFIRMED), modifier les dates (PENDING).
 * AGENT, ADMIN : changer le statut parmi les transitions possibles. ADMIN : supprimer une réservation annulée.
 */
@Component({
  selector: 'app-reservation-detail',
  imports: [FormsModule, RouterLink, DatePipe, CurrencyPipe],
  templateUrl: './reservation-detail.html',
  styleUrl: './reservations.scss',
})
export class ReservationDetail implements OnInit {
  private readonly api = inject(ReservationsApi);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  /** Paramètre de route :id. */
  private id = '';

  protected readonly staff = hasRole('AGENT', 'ADMIN');
  protected readonly admin = hasRole('ADMIN');
  protected readonly me = currentUserId();
  protected readonly statusLabels = STATUS_LABELS;
  protected readonly transitionLabels = TRANSITION_LABELS;
  protected readonly vehicleLabel = vehicleLabel;
  protected readonly agencyName = agencyName;
  protected readonly agencies = AGENCIES;
  protected readonly minDate = today();

  protected readonly reservation = signal<Reservation | null>(null);
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly notice = signal((history.state as { flash?: string } | null)?.flash ?? '');

  protected readonly reason = signal('');
  protected readonly comment = signal('');
  protected readonly editing = signal(false);
  protected readonly newStart = signal('');
  protected readonly newEnd = signal('');
  protected readonly newReturnAgency = signal('');

  protected readonly cancellable = computed(
    () => this.reservation()?.allowedTransitions.includes('CANCELLED') ?? false,
  );
  protected readonly editable = computed(() => this.reservation()?.status === 'PENDING');
  /** Transitions proposées aux AGENT / ADMIN (l'annulation a son propre bouton, avec un motif). */
  protected readonly staffTransitions = computed(() =>
    (this.reservation()?.allowedTransitions ?? []).filter((s) => s !== 'CANCELLED'),
  );
  protected readonly datesValid = computed(
    () =>
      !!this.newStart() &&
      !!this.newEnd() &&
      this.newStart() >= this.minDate &&
      this.newEnd() > this.newStart(),
  );

  ngOnInit(): void {
    this.id = this.route.snapshot.paramMap.get('id') ?? '';
    this.api.get(this.id).subscribe({
      next: (r) => {
        this.reservation.set(r);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(reservationError(err));
        this.loading.set(false);
      },
    });
  }

  protected who(changedBy: string | null): string {
    if (changedBy === null) return 'automatique';
    if (changedBy === this.me) return 'vous';
    return changedBy === this.reservation()?.userId ? 'le client' : 'agence';
  }

  protected cancel(): void {
    if (!confirm('Annuler cette réservation ? Les paiements déjà faits seront remboursés.')) {
      return;
    }
    this.run(this.api.cancel(this.id, this.reason().trim() || null), 'Réservation annulée.');
  }

  protected changeStatus(status: ReservationStatus): void {
    this.run(
      this.api.changeStatus(this.id, status, this.comment().trim() || null),
      `Statut : ${STATUS_LABELS[status]}.`,
    );
  }

  protected startEdit(): void {
    const r = this.reservation()!;
    this.newStart.set(r.startDate);
    this.newEnd.set(r.endDate);
    this.newReturnAgency.set(r.returnAgencyId === r.pickupAgencyId ? '' : r.returnAgencyId);
    this.editing.set(true);
  }

  protected saveDates(): void {
    if (!this.datesValid()) {
      return;
    }
    this.run(
      this.api.update(this.id, {
        startDate: this.newStart(),
        endDate: this.newEnd(),
        returnAgencyId: this.newReturnAgency() || null,
      }),
      'Dates modifiées, prix recalculé.',
      () => this.editing.set(false),
    );
  }

  protected remove(): void {
    if (!confirm('Supprimer définitivement cette réservation annulée ?')) {
      return;
    }
    this.busy.set(true);
    this.api.delete(this.id).subscribe({
      next: () =>
        this.router.navigate(['/reservations'], { state: { flash: 'Réservation supprimée.' } }),
      error: (err) => {
        this.error.set(reservationError(err));
        this.busy.set(false);
      },
    });
  }

  private run(call: ReturnType<ReservationsApi['get']>, message: string, done?: () => void): void {
    this.busy.set(true);
    this.error.set('');
    this.notice.set('');
    call.subscribe({
      next: (r) => {
        this.reservation.set(r);
        this.notice.set(message);
        this.reason.set('');
        this.comment.set('');
        this.busy.set(false);
        done?.();
      },
      error: (err) => {
        this.error.set(reservationError(err));
        this.busy.set(false);
      },
    });
  }
}
