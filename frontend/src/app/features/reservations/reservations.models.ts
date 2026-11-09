/** Formats de reservation-service (docs/openapi/reservation-service.yaml). */

export type ReservationStatus = 'PENDING' | 'CONFIRMED' | 'ONGOING' | 'COMPLETED' | 'CANCELLED';

export const STATUSES: ReservationStatus[] = [
  'PENDING',
  'CONFIRMED',
  'ONGOING',
  'COMPLETED',
  'CANCELLED',
];

export const STATUS_LABELS: Record<ReservationStatus, string> = {
  PENDING: 'En attente de paiement',
  CONFIRMED: 'Confirmée',
  ONGOING: 'En cours',
  COMPLETED: 'Terminée',
  CANCELLED: 'Annulée',
};

/** Libellé du bouton qui mène à chaque statut (changement par un AGENT ou un ADMIN). */
export const TRANSITION_LABELS: Record<ReservationStatus, string> = {
  PENDING: 'Remettre en attente',
  CONFIRMED: 'Confirmer',
  ONGOING: 'Remettre le véhicule',
  COMPLETED: 'Terminer la location',
  CANCELLED: 'Annuler',
};

export interface StatusHistoryEntry {
  fromStatus: ReservationStatus | null;
  toStatus: ReservationStatus;
  changedAt: string;
  changedBy: string | null;
  comment: string | null;
}

export interface Reservation {
  id: string;
  userId: string;
  vehicleId: string;
  pickupAgencyId: string;
  returnAgencyId: string;
  startDate: string;
  endDate: string;
  status: ReservationStatus;
  totalPrice: number;
  depositAmount: number;
  currency: string;
  categoryId: string | null;
  allowedTransitions: ReservationStatus[];
  createdAt: string;
  updatedAt: string;
  statusHistory?: StatusHistoryEntry[];
}

export interface ReservationFilter {
  page: number;
  size: number;
  status?: ReservationStatus | '';
  userId?: string;
  vehicleId?: string;
}

export interface BookingRequest {
  vehicleId: string;
  startDate: string;
  endDate: string;
  returnAgencyId?: string | null;
  userId?: string | null;
}

export interface Quote {
  vehicleId: string;
  categoryId: string;
  categoryName: string;
  pickupAgencyId: string;
  returnAgencyId: string;
  startDate: string;
  endDate: string;
  days: number;
  dailyRate: number;
  totalPrice: number;
  depositAmount: number;
  currency: string;
  /** Prix conseillé par ml-service ; null si le modèle est indisponible (le prix fixe s'applique). */
  suggestedPrice: {
    dailyPrice: number;
    totalPrice: number;
    lowerBound: number;
    upperBound: number;
    modelVersion: string;
  } | null;
}

export const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export interface Agency {
  id: string;
  name: string;
}

/** Agences de docs/demo-ids.md (vehicle-service n'expose pas encore de liste d'agences au frontend). */
export const AGENCIES: Agency[] = [
  { id: 'eb825ac4-855b-499b-b37a-b4c37bbd6b4e', name: 'Agence Tunis' },
  { id: '1deb1dea-ab54-436d-8ed7-c3322f782c88', name: 'Agence Sousse' },
];

export interface VehicleOption {
  id: string;
  label: string;
  category: string;
  agency: string;
}

/**
 * Véhicules de docs/demo-ids.md, pour choisir sans connaître les UUID tant que le module Véhicules (Yosra)
 * n'est pas branché. Un autre véhicule reste possible en saisissant son UUID.
 */
export const DEMO_VEHICLES: VehicleOption[] = [
  {
    id: 'ecdcc60f-ed6a-4007-8622-2b568338ef7a',
    label: 'Peugeot 208 · 123 TU 4501',
    category: 'Économique',
    agency: 'Tunis',
  },
  {
    id: '9f03ab2d-97a5-4c08-a952-dc6e446370d1',
    label: 'Renault Clio · 124 TU 4502',
    category: 'Économique',
    agency: 'Tunis',
  },
  {
    id: 'a1154300-bea1-4072-b750-2b00cf1f0bcd',
    label: 'Kia Sportage · 125 TU 4503',
    category: 'SUV',
    agency: 'Sousse',
  },
  {
    id: '2657e174-f016-4f0c-9485-0c63141432e3',
    label: 'Hyundai Tucson · 126 TU 4504',
    category: 'SUV',
    agency: 'Tunis',
  },
  {
    id: '15073301-d2df-45b0-9055-6b34fe40c07e',
    label: 'Mercedes Classe C · 127 TU 4505',
    category: 'Luxe',
    agency: 'Tunis',
  },
  {
    id: '4d23ae40-9d0d-47e0-a5bc-60dc9690242e',
    label: 'Volkswagen Polo · 128 TU 4506',
    category: 'Économique',
    agency: 'Sousse',
  },
];

export function vehicleLabel(id: string): string {
  return DEMO_VEHICLES.find((v) => v.id === id)?.label ?? id.slice(0, 8) + '…';
}

export function agencyName(id: string): string {
  return AGENCIES.find((a) => a.id === id)?.name ?? id.slice(0, 8) + '…';
}

/** Date du jour (fuseau du navigateur) au format YYYY-MM-DD. */
export function today(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function addDays(date: string, days: number): string {
  const d = new Date(date + 'T00:00:00');
  d.setDate(d.getDate() + days);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
