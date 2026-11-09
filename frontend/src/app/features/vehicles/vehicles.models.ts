/** Formats de vehicle-service (docs/openapi/vehicle-service.yaml). */

export type Fuel = 'GASOLINE' | 'DIESEL' | 'HYBRID' | 'ELECTRIC';
export type Transmission = 'MANUAL' | 'AUTOMATIC';
export type VehicleStatus = 'AVAILABLE' | 'RENTED' | 'MAINTENANCE' | 'OUT_OF_SERVICE';

export interface Category {
  id: string;
  name: string;
  dailyRate: number;
  deposit: number;
}

export interface Agency {
  id: string;
  name: string;
  city: string;
  address: string;
}

export interface VehicleInput {
  plate: string;
  brand: string;
  model: string;
  year: number;
  fuel: Fuel;
  transmission: Transmission;
  seats: number;
  mileage: number;
  status: VehicleStatus;
  categoryId: string;
  agencyId: string;
}

export interface Vehicle extends VehicleInput {
  id: string;
  category: Category;
  agency: Agency;
}

export interface Maintenance {
  id: string;
  vehicleId: string;
  type: string;
  date: string;
  cost: number;
}

export interface MaintenanceInput {
  type: string;
  date: string;
  cost: number;
}

export interface VehicleFilter {
  page: number;
  size: number;
  categoryId?: string;
  agencyId?: string;
  fuel?: Fuel | '';
  transmission?: Transmission | '';
  status?: VehicleStatus | '';
  maxPrice?: number | null;
}

export const FUEL_LABELS: Record<Fuel, string> = {
  GASOLINE: 'Essence',
  DIESEL: 'Diesel',
  HYBRID: 'Hybride',
  ELECTRIC: 'Électrique',
};

export const TRANSMISSION_LABELS: Record<Transmission, string> = {
  MANUAL: 'Manuelle',
  AUTOMATIC: 'Automatique',
};

export const STATUS_LABELS: Record<VehicleStatus, string> = {
  AVAILABLE: 'Disponible',
  RENTED: 'Louée',
  MAINTENANCE: 'En maintenance',
  OUT_OF_SERVICE: 'Hors service',
};

export const FUELS = Object.keys(FUEL_LABELS) as Fuel[];
export const TRANSMISSIONS = Object.keys(TRANSMISSION_LABELS) as Transmission[];
export const STATUSES = Object.keys(STATUS_LABELS) as VehicleStatus[];