/** Types of payment-service (docs/openapi/payment-service.yaml). Amounts in TND. */

export type PaymentMethod = 'CARD' | 'CASH' | 'TRANSFER';
export type PaymentType = 'RENTAL' | 'DEPOSIT' | 'EXTRA_FEE' | 'REFUND';
export type PaymentStatus = 'PENDING' | 'SUCCEEDED' | 'FAILED' | 'REFUNDED';
export type ExtraFeeType = 'LATE' | 'FUEL' | 'MILEAGE' | 'DAMAGE';

export interface Payment {
  id: string;
  reservationId: string;
  userId: string;
  amount: number;
  currency: string;
  method: PaymentMethod | null;
  type: PaymentType;
  status: PaymentStatus;
  transactionRef: string | null;
  feeType: ExtraFeeType | null;
  failureReason: string | null;
  originalPaymentId: string | null;
  createdAt: string;
  updatedAt: string;
}

export interface InvoiceLine {
  id: string;
  label: string;
  type: PaymentType;
  feeType: ExtraFeeType | null;
  amount: number;
  paymentId: string | null;
}

export interface Invoice {
  id: string;
  number: string;
  reservationId: string;
  userId: string;
  total: number;
  currency: string;
  issuedAt: string;
  lines: InvoiceLine[];
}

/** Body of POST /api/payments. cardToken is a simulated token: never a real card number. */
export interface CreatePaymentRequest {
  reservationId: string;
  type: 'RENTAL' | 'DEPOSIT';
  method: PaymentMethod;
  amount: number;
  cardToken?: string;
}

export interface PayPendingRequest {
  method: 'CARD' | 'CASH';
  cardToken?: string;
}

export interface PaymentFilters {
  reservationId?: string;
  status?: PaymentStatus | '';
  type?: PaymentType | '';
}

export const STATUS_LABELS: Record<PaymentStatus, string> = {
  PENDING: 'En attente',
  SUCCEEDED: 'Réussi',
  FAILED: 'Échoué',
  REFUNDED: 'Remboursé',
};

export const TYPE_LABELS: Record<PaymentType, string> = {
  RENTAL: 'Location',
  DEPOSIT: 'Caution',
  EXTRA_FEE: 'Frais de retour',
  REFUND: 'Remboursement',
};

export const METHOD_LABELS: Record<PaymentMethod, string> = {
  CARD: 'Carte',
  CASH: 'Espèces',
  TRANSFER: 'Virement',
};

export const FEE_LABELS: Record<ExtraFeeType, string> = {
  LATE: 'Retard',
  FUEL: 'Carburant',
  MILEAGE: 'Kilométrage',
  DAMAGE: 'Dommages',
};
