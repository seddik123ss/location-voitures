package com.location.loyaltyservice.account;

/**
 * WELCOME : 100 points à l'ouverture. EARN : location terminée. REDEEM : conversion en bon (négatif).
 * ADJUST : geste commercial ou correction par un ADMIN (positif ou négatif).
 */
public enum TransactionType {
    WELCOME, EARN, REDEEM, ADJUST
}
