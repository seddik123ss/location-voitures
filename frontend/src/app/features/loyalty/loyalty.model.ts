/** Formats de loyalty-service (docs/openapi/loyalty-service.yaml). */
export type Tier = 'BRONZE' | 'SILVER' | 'GOLD';
export type AccountStatus = 'ACTIVE' | 'CLOSED';
export type TransactionType = 'WELCOME' | 'EARN' | 'REDEEM' | 'ADJUST';
export type DiscountType = 'PERCENT' | 'AMOUNT';
export type PromoState = 'VALID' | 'UPCOMING' | 'EXPIRED' | 'EXHAUSTED' | 'INACTIVE';

export const TIERS: Tier[] = ['BRONZE', 'SILVER', 'GOLD'];
export const DISCOUNT_TYPES: DiscountType[] = ['PERCENT', 'AMOUNT'];
/** A-Z, 0-9 et tiret, 4 à 20 caractères (mis en majuscules par le service). */
export const CODE_PATTERN = /^[A-Za-z0-9-]{4,20}$/;

export const TRANSACTION_LABELS: Record<TransactionType, string> = {
  WELCOME: 'Bienvenue',
  EARN: 'Location terminée',
  REDEEM: 'Conversion en bon',
  ADJUST: 'Ajustement',
};

export const STATE_LABELS: Record<PromoState, string> = {
  VALID: 'Valide',
  UPCOMING: 'À venir',
  EXPIRED: 'Expiré',
  EXHAUSTED: 'Épuisé',
  INACTIVE: 'Désactivé',
};

export interface LoyaltyAccount {
  userId: string;
  balance: number;
  lifetimePoints: number;
  tier: Tier;
  status: AccountStatus;
  nextTier: Tier | null;
  pointsToNextTier: number;
  progress: number;
  createdAt: string;
  updatedAt: string;
}

export interface PointTransaction {
  id: string;
  type: TransactionType;
  points: number;
  reason: string;
  reservationId: string | null;
  createdAt: string;
}

export interface PromoCode {
  id: string;
  code: string;
  discountType: DiscountType;
  discountValue: number;
  minAmount: number | null;
  minTier: Tier | null;
  validFrom: string;
  validUntil: string;
  maxUses: number | null;
  maxUsesPerUser: number;
  usedCount: number;
  ownerUserId: string | null;
  active: boolean;
  state: PromoState;
  createdAt: string;
}

export interface PromoCodeRequest {
  code?: string;
  discountType: DiscountType;
  discountValue: number;
  minAmount: number | null;
  minTier: Tier | null;
  validFrom: string;
  validUntil: string;
  maxUses: number | null;
  maxUsesPerUser: number;
  active?: boolean;
}

export interface DiscountPreview {
  code: string;
  amountBefore: number;
  discount: number;
  amountAfter: number;
}
