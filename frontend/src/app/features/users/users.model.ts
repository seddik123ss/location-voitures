/** Types des réponses de user-service (voir docs/openapi/user-service.yaml). */

export type UserRole = 'CLIENT' | 'AGENT' | 'ADMIN';

export const ROLES: UserRole[] = ['CLIENT', 'AGENT', 'ADMIN'];

export const LICENSE_CATEGORIES = ['A', 'A1', 'B', 'BE', 'C', 'CE', 'D', 'D1', 'DE', 'H'];

/** Mêmes formats que le backend (une valeur hors format y renvoie 422). */
export const EMAIL_PATTERN = /^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/;
export const PHONE_PATTERN = /^\+?[0-9]{8,15}$/;
export const ZIP_PATTERN = /^[0-9]{4}$/;
export const UUID_PATTERN = /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/;

export interface License {
  id: string;
  userId: string;
  number: string;
  category: string;
  issueDate: string;
  expiryDate: string;
  verified: boolean;
  expired: boolean;
}

export interface Address {
  id: string;
  street: string;
  city: string;
  zipCode: string;
  userId: string;
}

export interface UserSummary {
  id: string;
  firstname: string;
  lastname: string;
  email: string;
  phone: string | null;
  role: UserRole;
  active: boolean;
  createdAt: string;
}

export interface UserProfile extends UserSummary {
  updatedAt: string;
  license: License | null;
  addresses: Address[];
}

export interface Reservation {
  id: string;
  userId: string;
  vehicleId: string;
  pickupAgencyId: string;
  returnAgencyId: string;
  startDate: string;
  endDate: string;
  status: string;
  totalPrice: number;
  depositAmount: number;
  currency: string;
}

export interface CreateUserRequest {
  id: string;
  firstname: string;
  lastname: string;
  email: string;
  phone: string | null;
  role: UserRole;
  active: boolean;
}

export type UpdateUserRequest = Omit<CreateUserRequest, 'id'>;

export interface AddressRequest {
  street: string;
  city: string;
  zipCode: string;
}

export interface LicenseRequest {
  number: string;
  category: string;
  issueDate: string;
  expiryDate: string;
}

export interface CreateLicenseRequest extends LicenseRequest {
  userId: string;
}

export const RESERVATION_STATUS: Record<string, string> = {
  PENDING: 'En attente',
  CONFIRMED: 'Confirmée',
  ONGOING: 'En cours',
  COMPLETED: 'Terminée',
  CANCELLED: 'Annulée',
};
