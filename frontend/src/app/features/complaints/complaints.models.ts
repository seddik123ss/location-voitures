/** Formats de complaint-service (docs/openapi/complaint-service.yaml). */

export type ComplaintStatus = 'OPEN' | 'IN_PROGRESS' | 'RESOLVED' | 'REJECTED';
export type ComplaintCategory = 'BILLING' | 'VEHICLE' | 'SERVICE' | 'DAMAGE_DISPUTE';
export type ComplaintPriority = 'LOW' | 'MEDIUM' | 'HIGH';
export type AuthorRole = 'CLIENT' | 'AGENT' | 'ADMIN';

export interface ComplaintSummary {
  id: string;
  userId: string;
  reservationId: string | null;
  contractId: string | null;
  category: ComplaintCategory;
  priority: ComplaintPriority;
  status: ComplaintStatus;
  subject: string;
  description: string;
  createdAt: string;
  updatedAt: string;
}

export interface ComplaintMessage {
  id: string;
  complaintId: string;
  authorId: string;
  authorRole: AuthorRole;
  content: string;
  createdAt: string;
}

export interface StatusHistoryEntry {
  id: string;
  fromStatus: ComplaintStatus | null;
  toStatus: ComplaintStatus;
  changedBy: string;
  changedByRole: AuthorRole;
  comment: string | null;
  changedAt: string;
}

export interface ComplaintDetail extends ComplaintSummary {
  /** Statuts accessibles depuis le statut actuel (vide pour un état final). */
  allowedTransitions: ComplaintStatus[];
  messages: ComplaintMessage[];
  statusHistory: StatusHistoryEntry[];
}

export interface ComplaintFilter {
  page: number;
  size: number;
  status?: ComplaintStatus | '';
  category?: ComplaintCategory | '';
  priority?: ComplaintPriority | '';
  userId?: string;
}

export interface CreateComplaint {
  category: ComplaintCategory;
  priority: ComplaintPriority;
  subject: string;
  description: string;
  reservationId: string | null;
  contractId: string | null;
}

export interface UpdateComplaint {
  category: ComplaintCategory;
  subject: string;
  description: string;
}

/** Réservation renvoyée par reservation-service (format de docs/internal-api.md), utilisée pour le choix à la création. */
export interface ReservationOption {
  id: string;
  vehicleId?: string;
  startDate?: string;
  endDate?: string;
  status?: string;
}

export const STATUS_LABELS: Record<ComplaintStatus, string> = {
  OPEN: 'Ouverte',
  IN_PROGRESS: 'En cours',
  RESOLVED: 'Résolue',
  REJECTED: 'Rejetée',
};

export const CATEGORY_LABELS: Record<ComplaintCategory, string> = {
  BILLING: 'Facturation',
  VEHICLE: 'Véhicule',
  SERVICE: 'Service',
  DAMAGE_DISPUTE: 'Litige sur dommages',
};

export const PRIORITY_LABELS: Record<ComplaintPriority, string> = {
  LOW: 'Basse',
  MEDIUM: 'Moyenne',
  HIGH: 'Haute',
};

export const ROLE_LABELS: Record<AuthorRole, string> = {
  CLIENT: 'Client',
  AGENT: 'Agent',
  ADMIN: 'Administrateur',
};

export const STATUSES = Object.keys(STATUS_LABELS) as ComplaintStatus[];
export const CATEGORIES = Object.keys(CATEGORY_LABELS) as ComplaintCategory[];
export const PRIORITIES = Object.keys(PRIORITY_LABELS) as ComplaintPriority[];

/** RESOLVED et REJECTED : plus aucune action possible. */
export function isFinal(status: ComplaintStatus): boolean {
  return status === 'RESOLVED' || status === 'REJECTED';
}

export const UUID_PATTERN = /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/;
