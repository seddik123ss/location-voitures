import { Routes } from '@angular/router';

import { InvoiceDetail } from './invoice-detail';
import { InvoiceList } from './invoice-list';
import { PaymentList } from './payment-list';
import { PaymentNew } from './payment-new';
import { PaymentPay } from './payment-pay';
import { PaymentsLayout } from './payments-layout';

/**
 * Routes du module Paiements (Fadi). Accès : CLIENT, AGENT, ADMIN (garde dans app.routes.ts).
 * Ce que chaque rôle voit est filtré par payment-service à partir du token (le CLIENT ne reçoit
 * que ses paiements et factures) ; l'interface masque en plus les actions réservées à ADMIN.
 *
 *   /payments                    historique (CLIENT : les siens ; AGENT/ADMIN : tous + actions ADMIN)
 *   /payments/new                payer une réservation (?reservationId=&type=&amount= pour préremplir)
 *   /payments/:id/pay            régler un paiement en attente (frais de retour)
 *   /payments/invoices           factures
 *   /payments/invoices/:id       détail d'une facture (imprimable)
 */
export const PAYMENTS_ROUTES: Routes = [
  {
    path: '',
    component: PaymentsLayout,
    children: [
      { path: '', component: PaymentList, title: 'Paiements' },
      { path: 'new', component: PaymentNew, title: 'Payer une réservation' },
      { path: 'invoices', component: InvoiceList, title: 'Factures' },
      { path: 'invoices/:id', component: InvoiceDetail, title: 'Facture' },
      { path: ':id/pay', component: PaymentPay, title: 'Régler un paiement' },
    ],
  },
];
