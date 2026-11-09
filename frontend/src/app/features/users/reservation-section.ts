import { DatePipe, DecimalPipe } from '@angular/common';
import { Component, OnInit, inject, input, signal } from '@angular/core';
import { Page, errorMessage } from '../../core/api';
import { UsersApi } from './users.api';
import { RESERVATION_STATUS, Reservation } from './users.model';

/**
 * Historique des réservations d'un client : GET /api/users/{id}/reservations
 * (user-service appelle reservation-service avec OpenFeign ; 503 si celui-ci est arrêté).
 */
@Component({
  selector: 'app-reservation-section',
  imports: [DatePipe, DecimalPipe],
  styleUrl: './users.scss',
  template: `
    <section class="card">
      <h2>Historique des réservations</h2>
      @if (error()) {
        <div class="alert">{{ error() }}</div>
      } @else if (loading()) {
        <p class="muted">Chargement...</p>
      } @else if (data(); as p) {
        @if (p.items.length === 0) {
          <p class="muted">Aucune réservation.</p>
        } @else {
          <table>
            <thead><tr><th>Du</th><th>Au</th><th>Statut</th><th>Prix total</th><th>Caution</th></tr></thead>
            <tbody>
              @for (r of p.items; track r.id) {
                <tr>
                  <td>{{ r.startDate | date: 'dd/MM/yyyy' }}</td>
                  <td>{{ r.endDate | date: 'dd/MM/yyyy' }}</td>
                  <td><span class="badge">{{ statusLabel(r.status) }}</span></td>
                  <td>{{ r.totalPrice | number: '1.3-3' }} {{ r.currency }}</td>
                  <td>{{ r.depositAmount | number: '1.3-3' }} {{ r.currency }}</td>
                </tr>
              }
            </tbody>
          </table>
          @if (p.total > p.size) {
            <div class="pager">
              <button type="button" [disabled]="p.page === 0" (click)="load(p.page - 1)">Précédent</button>
              <span class="muted">Page {{ p.page + 1 }} / {{ pages(p) }}</span>
              <button type="button" [disabled]="p.page + 1 >= pages(p)" (click)="load(p.page + 1)">Suivant</button>
            </div>
          }
        }
      }
    </section>
  `,
})
export class ReservationSection implements OnInit {
  readonly userId = input.required<string>();

  private readonly api = inject(UsersApi);
  private readonly size = 5;
  protected readonly data = signal<Page<Reservation> | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal('');

  ngOnInit(): void {
    this.load(0);
  }

  protected load(page: number): void {
    this.loading.set(true);
    this.error.set('');
    this.api.reservations(this.userId(), page, this.size).subscribe({
      next: (p) => {
        this.data.set(p);
        this.loading.set(false);
      },
      error: (e) => {
        this.error.set(errorMessage(e));
        this.loading.set(false);
      },
    });
  }

  protected statusLabel(status: string): string {
    return RESERVATION_STATUS[status] ?? status;
  }

  protected pages(p: Page<Reservation>): number {
    return Math.max(1, Math.ceil(p.total / p.size));
  }
}
