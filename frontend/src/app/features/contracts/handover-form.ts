import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { errorMessage } from '../../core/api';
import { ContractsService } from './contracts.service';

/** Remise des clés (AGENT / ADMIN) : crée le contrat et l'état des lieux de départ. */
@Component({
  selector: 'app-handover-form',
  imports: [FormsModule, RouterLink],
  styleUrl: './contracts.scss',
  template: `
    <div class="head">
      <h1>Remise des clés</h1>
      <a class="btn secondary" routerLink="..">Retour à la liste</a>
    </div>

    <p class="muted">
      La réservation doit être confirmée et sa caution payée. Un état des lieux de départ est enregistré avec le contrat.
    </p>

    @if (error()) {
      <div class="error">{{ error() }}</div>
    }

    <form class="card" #f="ngForm" (ngSubmit)="submit()">
      <div class="grid">
        <label>
          Identifiant de la réservation
          <input name="reservationId" [(ngModel)]="reservationId" required
                 pattern="[0-9a-fA-F-]{36}" placeholder="33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8" />
        </label>
        <label>
          Kilométrage au départ
          <input name="mileage" type="number" min="0" [(ngModel)]="mileage" required />
        </label>
        <label>
          Carburant au départ : {{ fuelPercent() }} %
          <input name="fuelLevel" type="range" min="0" max="100" step="5" [(ngModel)]="fuel" />
        </label>
      </div>
      <label style="margin-top: 12px">
        Dommages déjà présents (facultatif)
        <textarea name="damages" [(ngModel)]="damages" maxlength="2000"></textarea>
      </label>
      <label style="margin-top: 12px">
        Liens des photos, un par ligne (facultatif)
        <textarea name="photos" [(ngModel)]="photos"></textarea>
      </label>
      <div class="actions">
        <button type="submit" [disabled]="f.invalid || saving()">{{ saving() ? 'Enregistrement…' : 'Créer le contrat' }}</button>
      </div>
    </form>
  `,
})
export class HandoverForm {
  private readonly api = inject(ContractsService);
  private readonly router = inject(Router);

  protected reservationId = '';
  protected mileage: number | null = null;
  protected fuel = 100;
  protected damages = '';
  protected photos = '';

  protected readonly saving = signal(false);
  protected readonly error = signal('');

  protected fuelPercent(): number {
    return this.fuel;
  }

  submit(): void {
    this.saving.set(true);
    this.error.set('');
    this.api
      .handover({
        reservationId: this.reservationId.trim(),
        mileage: Number(this.mileage),
        fuelLevel: this.fuel / 100,
        damages: this.damages.trim() || null,
        photos: this.photos.split('\n').map((p) => p.trim()).filter((p) => p.length > 0),
      })
      .subscribe({
        next: (c) => this.router.navigate(['/contracts', c.id]),
        error: (e) => {
          this.error.set(errorMessage(e));
          this.saving.set(false);
        },
      });
  }
}
