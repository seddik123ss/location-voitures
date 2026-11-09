import { DatePipe, DecimalPipe, PercentPipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { errorMessage } from '../../core/api';
import { hasRole } from '../../core/keycloak';
import { Contract, ContractsService, FEE_LABELS, Inspection, ReturnSettlement } from './contracts.service';

/** Détail d'un contrat : états des lieux, frais ; formulaire de retour du véhicule pour AGENT / ADMIN. */
@Component({
  selector: 'app-contract-detail',
  imports: [RouterLink, DatePipe, DecimalPipe, PercentPipe, FormsModule],
  styleUrl: './contracts.scss',
  template: `
    <div class="head">
      <h1>Contrat {{ contract()?.number }}</h1>
      <a class="btn secondary" routerLink="..">Retour à la liste</a>
    </div>

    @if (error()) {
      <div class="error">{{ error() }}</div>
    }
    @if (settlement(); as s) {
      <div class="success">
        Véhicule rendu, contrat clôturé. Frais : {{ s.totalExtraFees | number: '1.3-3' : 'fr' }} TND.
        Caution à libérer : {{ s.depositToRelease | number: '1.3-3' : 'fr' }} TND.
      </div>
    }

    @if (contract(); as c) {
      <div class="card">
        <dl>
          <dt>Statut</dt>
          <dd><span class="badge" [class]="c.status">{{ c.status === 'ACTIVE' ? 'En cours' : 'Clôturé' }}</span></dd>
          <dt>Signé le</dt>
          <dd>{{ c.signedAt | date: 'dd/MM/yyyy HH:mm' }}</dd>
          <dt>Clôturé le</dt>
          <dd>{{ c.closedAt ? (c.closedAt | date: 'dd/MM/yyyy HH:mm') : '—' }}</dd>
          <dt>Réservation</dt>
          <dd>{{ c.reservationId }}</dd>
          <dt>Véhicule</dt>
          <dd>{{ c.vehicleId }}</dd>
          @if (isStaff) {
            <dt>Client</dt>
            <dd>{{ c.userId }}</dd>
          }
        </dl>
      </div>

      <h2>États des lieux</h2>
      <table>
        <thead>
          <tr><th>Type</th><th>Date</th><th class="num">Kilométrage</th><th class="num">Carburant</th><th>Dommages</th><th>Photos</th></tr>
        </thead>
        <tbody>
          @for (i of c.inspections; track i.id) {
            <tr>
              <td>{{ i.type === 'PICKUP' ? 'Départ' : 'Retour' }}</td>
              <td>{{ i.inspectedAt | date: 'dd/MM/yyyy HH:mm' }}</td>
              <td class="num">{{ i.mileage | number: '1.0-0' : 'fr' }} km</td>
              <td class="num">{{ i.fuelLevel | percent: '1.0-0' : 'fr' }}</td>
              <td>{{ i.damages || '—' }}</td>
              <td>
                @for (p of i.photos; track p; let n = $index) {
                  <a [href]="p" target="_blank" rel="noopener">Photo {{ n + 1 }}</a>&nbsp;
                } @empty { — }
              </td>
            </tr>
          }
        </tbody>
      </table>

      <h2>Frais supplémentaires</h2>
      @if (c.extraFees.length === 0) {
        <p class="muted">{{ c.status === 'ACTIVE' ? 'Calculés au retour du véhicule.' : 'Aucun frais.' }}</p>
      } @else {
        <table>
          <thead><tr><th>Type</th><th class="num">Montant (TND)</th></tr></thead>
          <tbody>
            @for (f of c.extraFees; track f.id) {
              <tr><td>{{ feeLabels[f.type] }}</td><td class="num">{{ f.amount | number: '1.3-3' : 'fr' }}</td></tr>
            }
            <tr class="total"><td>Total</td><td class="num">{{ c.totalExtraFees | number: '1.3-3' : 'fr' }}</td></tr>
          </tbody>
        </table>
      }

      @if (isStaff && c.status === 'ACTIVE') {
        <h2>Retour du véhicule</h2>
        <form class="card" #f="ngForm" (ngSubmit)="returnVehicle(c)">
          <p class="muted" style="margin-top: 0">
            Départ : {{ pickup(c)?.mileage | number: '1.0-0' : 'fr' }} km, carburant {{ pickup(c)?.fuelLevel | percent: '1.0-0' : 'fr' }}.
            Les frais (retard, carburant, kilomètres, dommages) sont calculés automatiquement.
          </p>
          <div class="grid">
            <label>
              Kilométrage au retour
              <input name="mileage" type="number" [min]="pickup(c)?.mileage ?? 0" [(ngModel)]="ret.mileage" required />
              @if (tooLow(c)) {
                <span class="field-error">Doit être au moins {{ pickup(c)?.mileage | number: '1.0-0' : 'fr' }} km (kilométrage au départ).</span>
              }
            </label>
            <label>
              Carburant au retour : {{ ret.fuel }} %
              <input name="fuel" type="range" min="0" max="100" step="5" [(ngModel)]="ret.fuel" />
            </label>
            <label>
              Montant des dommages (TND)
              <input name="damageFee" type="number" min="0" step="0.001" [(ngModel)]="ret.damageFee" />
            </label>
          </div>
          <label style="margin-top: 12px">
            Nouveaux dommages constatés (facultatif)
            <textarea name="damages" [(ngModel)]="ret.damages" maxlength="2000"></textarea>
          </label>
          <label style="margin-top: 12px">
            Liens des photos, un par ligne (facultatif)
            <textarea name="photos" [(ngModel)]="ret.photos"></textarea>
          </label>
          @if (error()) {
            <div class="error" style="margin: 12px 0 0">{{ error() }}</div>
          }
          <div class="actions">
            <button type="submit" [disabled]="f.invalid || saving()">{{ saving() ? 'Enregistrement…' : 'Clôturer le contrat' }}</button>
            <button type="button" class="danger" [disabled]="saving()" (click)="remove(c)">Supprimer le contrat</button>
          </div>
        </form>
      }
    } @else if (!error()) {
      <p class="muted">Chargement…</p>
    }
  `,
})
export class ContractDetail implements OnInit {
  private readonly api = inject(ContractsService);
  private readonly router = inject(Router);

  private readonly route = inject(ActivatedRoute);

  protected readonly isStaff = hasRole('AGENT', 'ADMIN');
  protected readonly feeLabels = FEE_LABELS;
  protected readonly contract = signal<Contract | null>(null);
  protected readonly settlement = signal<ReturnSettlement | null>(null);
  protected readonly saving = signal(false);
  protected readonly error = signal('');

  protected ret = { mileage: null as number | null, fuel: 100, damageFee: 0, damages: '', photos: '' };

  ngOnInit(): void {
    const id = this.route.snapshot.paramMap.get('id')!;
    this.api.get(id).subscribe({
      next: (c) => this.contract.set(c),
      error: (e) => this.error.set(errorMessage(e)),
    });
  }

  protected pickup(c: Contract): Inspection | undefined {
    return c.inspections.find((i) => i.type === 'PICKUP');
  }

  /** Kilométrage saisi inférieur à celui du départ (le bouton reste grisé : on explique pourquoi). */
  protected tooLow(c: Contract): boolean {
    const start = this.pickup(c)?.mileage ?? 0;
    return this.ret.mileage !== null && `${this.ret.mileage}` !== '' && Number(this.ret.mileage) < start;
  }

  returnVehicle(c: Contract): void {
    this.saving.set(true);
    this.error.set('');
    this.api
      .returnVehicle(c.id, {
        mileage: Number(this.ret.mileage),
        fuelLevel: this.ret.fuel / 100,
        damageFee: Number(this.ret.damageFee) || 0,
        damages: this.ret.damages.trim() || null,
        photos: this.ret.photos.split('\n').map((p) => p.trim()).filter((p) => p.length > 0),
      })
      .subscribe({
        next: (s) => {
          this.settlement.set(s);
          this.contract.set(s.contract);
          this.saving.set(false);
        },
        error: (e) => {
          this.error.set(errorMessage(e));
          this.saving.set(false);
        },
      });
  }

  remove(c: Contract): void {
    if (!confirm(`Supprimer le contrat ${c.number} ? Cette action est définitive.`)) return;
    this.saving.set(true);
    this.api.delete(c.id).subscribe({
      next: () => this.router.navigate(['/contracts']),
      error: (e) => {
        this.error.set(errorMessage(e));
        this.saving.set(false);
      },
    });
  }
}
