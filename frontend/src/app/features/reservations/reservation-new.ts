import { CurrencyPipe, DecimalPipe } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { hasRole } from '../../core/keycloak';
import { fieldErrors, reservationError, ReservationsApi } from './reservations.api';
import {
  addDays,
  AGENCIES,
  BookingRequest,
  DEMO_VEHICLES,
  Quote,
  today,
  UUID_PATTERN,
} from './reservations.models';

const OTHER = 'other';

/**
 * Réserver : véhicule, dates, agence de retour (et client pour un AGENT ou un ADMIN).
 * « Voir le prix » demande un devis (prix fixe + prix conseillé par le modèle) avec les mêmes contrôles que la
 * création ; « Réserver » crée la réservation et ouvre son détail.
 */
@Component({
  selector: 'app-reservation-new',
  imports: [FormsModule, RouterLink, CurrencyPipe, DecimalPipe],
  templateUrl: './reservation-new.html',
  styleUrl: './reservations.scss',
})
export class ReservationNew {
  private readonly api = inject(ReservationsApi);
  private readonly router = inject(Router);

  protected readonly staff = hasRole('AGENT', 'ADMIN');
  protected readonly vehicles = DEMO_VEHICLES;
  protected readonly agencies = AGENCIES;
  protected readonly other = OTHER;
  protected readonly minDate = today();

  protected readonly vehicleChoice = signal(DEMO_VEHICLES[0].id);
  protected readonly customVehicleId = signal('');
  protected readonly startDate = signal(addDays(today(), 7));
  protected readonly endDate = signal(addDays(today(), 10));
  protected readonly returnAgencyId = signal('');
  protected readonly userId = signal('');

  protected readonly quote = signal<Quote | null>(null);
  /** Demande pour laquelle le devis affiché a été calculé : toute modification le rend périmé. */
  private readonly quotedFor = signal('');
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly fields = signal<Record<string, string>>({});

  protected readonly vehicleId = computed(() =>
    (this.vehicleChoice() === OTHER ? this.customVehicleId() : this.vehicleChoice())
      .trim()
      .toLowerCase(),
  );

  protected readonly request = computed<BookingRequest>(() => ({
    vehicleId: this.vehicleId(),
    startDate: this.startDate(),
    endDate: this.endDate(),
    returnAgencyId: this.returnAgencyId() || null,
    ...(this.staff ? { userId: this.userId().trim().toLowerCase() } : {}),
  }));

  /** Contrôles faits avant d'appeler le service (le service refait les siens : 400). */
  protected readonly clientErrors = computed(() => {
    const e: Record<string, string> = {};
    if (!UUID_PATTERN.test(this.vehicleId()))
      e['vehicleId'] = 'Choisissez un véhicule ou saisissez un UUID valide';
    if (!this.startDate()) e['startDate'] = 'Date de départ obligatoire';
    else if (this.startDate() < this.minDate)
      e['startDate'] = 'La date de départ ne peut pas être dans le passé';
    if (!this.endDate()) e['endDate'] = 'Date de retour obligatoire';
    else if (this.startDate() && this.endDate() <= this.startDate())
      e['endDate'] = 'Le retour doit être après le départ';
    if (this.staff && !UUID_PATTERN.test(this.userId().trim()))
      e['userId'] = 'UUID du client obligatoire';
    return e;
  });

  protected readonly valid = computed(() => Object.keys(this.clientErrors()).length === 0);
  protected readonly quoteIsCurrent = computed(
    () => this.quote() !== null && this.quotedFor() === JSON.stringify(this.request()),
  );

  /** Erreur à afficher sous un champ : celle du service si elle existe, sinon celle du formulaire. */
  protected fieldError(name: string): string {
    return this.fields()[name] ?? this.clientErrors()[name] ?? '';
  }

  protected changed(): void {
    this.error.set('');
    this.fields.set({});
  }

  protected askQuote(): void {
    if (!this.valid() || this.busy()) {
      return;
    }
    const request = this.request();
    this.start();
    this.api.quote(request).subscribe({
      next: (quote) => {
        this.quote.set(quote);
        this.quotedFor.set(JSON.stringify(request));
        this.busy.set(false);
      },
      error: (err) => this.fail(err),
    });
  }

  protected book(): void {
    if (!this.valid() || this.busy()) {
      return;
    }
    this.start();
    this.api.create(this.request()).subscribe({
      next: (reservation) =>
        this.router.navigate(['/reservations', reservation.id], {
          state: {
            flash: 'Réservation enregistrée. Elle sera confirmée après le paiement de la location.',
          },
        }),
      error: (err) => this.fail(err),
    });
  }

  private start(): void {
    this.busy.set(true);
    this.error.set('');
    this.fields.set({});
  }

  private fail(err: unknown): void {
    this.error.set(reservationError(err));
    this.fields.set((err as { status?: number })?.status === 400 ? fieldErrors(err) : {});
    this.quote.set(null);
    this.busy.set(false);
  }
}
