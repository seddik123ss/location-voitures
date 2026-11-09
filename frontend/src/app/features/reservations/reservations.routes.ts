import { Routes } from '@angular/router';
import { ReservationDetail } from './reservation-detail';
import { ReservationList } from './reservation-list';
import { ReservationNew } from './reservation-new';

/** Routes du module Réservations (Aziz) : liste, réservation d'un véhicule, détail. */
export const RESERVATIONS_ROUTES: Routes = [
  { path: '', component: ReservationList, title: 'Réservations' },
  { path: 'new', component: ReservationNew, title: 'Réserver un véhicule' },
  { path: ':id', component: ReservationDetail, title: 'Réservation' },
];
