import { DatePipe, DecimalPipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { errorMessage } from '../../core/api';
import { FEE_LABELS, Invoice, TYPE_LABELS } from './payment.models';
import { PaymentsService } from './payments.service';

/** One invoice with its lines; printable (the browser's print dialog can save it as PDF). */
@Component({
  selector: 'app-invoice-detail',
  imports: [RouterLink, DatePipe, DecimalPipe],
  template: `
    <p class="no-print"><a routerLink="..">← Retour aux factures</a></p>
    @if (error()) { <div class="alert error">{{ error() }}</div> }
    @if (invoice(); as inv) {
      <div class="card">
        <div class="actions" style="justify-content: space-between">
          <div>
            <h2 style="margin: 0">Facture {{ inv.number }}</h2>
            <div class="muted">Émise le {{ inv.issuedAt | date: 'dd/MM/yyyy HH:mm' }}</div>
          </div>
          <button type="button" class="no-print" (click)="print()">Imprimer / PDF</button>
        </div>
        <p>Réservation <code>{{ inv.reservationId }}</code><br />Client <code>{{ inv.userId }}</code></p>
        <table>
          <thead><tr><th>Désignation</th><th>Type</th><th class="num">Montant</th></tr></thead>
          <tbody>
            @for (line of inv.lines; track line.id) {
              <tr>
                <td>{{ line.label }}</td>
                <td>{{ typeLabels[line.type] }} @if (line.feeType) { ({{ feeLabels[line.feeType] }}) }</td>
                <td class="num">{{ line.amount | number: '1.3-3' }} {{ inv.currency }}</td>
              </tr>
            }
            <tr>
              <th colspan="2">Total</th>
              <th class="num">{{ inv.total | number: '1.3-3' }} {{ inv.currency }}</th>
            </tr>
          </tbody>
        </table>
      </div>
    }
  `,
})
export class InvoiceDetail implements OnInit {
  private readonly api = inject(PaymentsService);
  private readonly id = inject(ActivatedRoute).snapshot.paramMap.get('id') ?? '';
  protected readonly typeLabels = TYPE_LABELS;
  protected readonly feeLabels = FEE_LABELS;
  protected readonly invoice = signal<Invoice | null>(null);
  protected readonly error = signal<string | null>(null);

  ngOnInit(): void {
    this.api.getInvoice(this.id).subscribe({
      next: (inv) => this.invoice.set(inv),
      error: (err) => this.error.set(errorMessage(err)),
    });
  }

  protected print(): void {
    window.print();
  }
}
