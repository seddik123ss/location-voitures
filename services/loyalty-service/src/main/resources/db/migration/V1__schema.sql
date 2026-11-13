-- loyalty-service : schéma initial
-- Identifiants UUID stockés en CHAR(36) (même texte que dans Keycloak et les autres services).
-- Aucune clé étrangère vers une autre base : userId et reservationId sont de simples UUID.
-- Montants en DECIMAL(12,3), devise TND.

CREATE TABLE loyalty_accounts (
    user_id          CHAR(36)    NOT NULL,          -- = "sub" Keycloak = id dans user-service
    balance          INT         NOT NULL,          -- points disponibles
    lifetime_points  INT         NOT NULL,          -- total gagné depuis l'ouverture (sert au niveau)
    tier             VARCHAR(10) NOT NULL,          -- BRONZE, SILVER, GOLD
    status           VARCHAR(10) NOT NULL,          -- ACTIVE, CLOSED
    created_at       DATETIME(6) NOT NULL,
    updated_at       DATETIME(6) NOT NULL,
    CONSTRAINT pk_loyalty_accounts PRIMARY KEY (user_id),
    CONSTRAINT ck_accounts_balance CHECK (balance >= 0),
    CONSTRAINT ck_accounts_lifetime CHECK (lifetime_points >= 0),
    CONSTRAINT ck_accounts_tier CHECK (tier IN ('BRONZE', 'SILVER', 'GOLD')),
    CONSTRAINT ck_accounts_status CHECK (status IN ('ACTIVE', 'CLOSED'))
);

CREATE INDEX idx_accounts_tier ON loyalty_accounts (tier);

CREATE TABLE point_transactions (
    id              CHAR(36)     NOT NULL,
    user_id         CHAR(36)     NOT NULL,
    type            VARCHAR(10)  NOT NULL,          -- WELCOME, EARN, REDEEM, ADJUST
    points          INT          NOT NULL,          -- positif (gain) ou négatif (dépense)
    reason          VARCHAR(255) NOT NULL,
    reservation_id  CHAR(36)     NULL,              -- renseigné pour EARN
    created_at      DATETIME(6)  NOT NULL,
    CONSTRAINT pk_point_transactions PRIMARY KEY (id),
    CONSTRAINT fk_transactions_account FOREIGN KEY (user_id) REFERENCES loyalty_accounts (user_id),
    -- une réservation ne rapporte des points qu'une seule fois (filet de sécurité de l'idempotence)
    CONSTRAINT uk_transactions_type_reservation UNIQUE (type, reservation_id),
    CONSTRAINT ck_transactions_type CHECK (type IN ('WELCOME', 'EARN', 'REDEEM', 'ADJUST')),
    CONSTRAINT ck_transactions_points CHECK (points <> 0)
);

CREATE INDEX idx_transactions_user_date ON point_transactions (user_id, created_at);

CREATE TABLE promo_codes (
    id                 CHAR(36)       NOT NULL,
    code               VARCHAR(20)    NOT NULL,     -- A-Z 0-9 et tiret, toujours en majuscules
    discount_type      VARCHAR(10)    NOT NULL,     -- PERCENT, AMOUNT
    discount_value     DECIMAL(12, 3) NOT NULL,
    min_amount         DECIMAL(12, 3) NULL,
    min_tier           VARCHAR(10)    NULL,
    valid_from         DATE           NOT NULL,
    valid_until        DATE           NOT NULL,     -- inclus
    max_uses           INT            NULL,         -- null = illimité
    max_uses_per_user  INT            NOT NULL DEFAULT 1,
    used_count         INT            NOT NULL DEFAULT 0,
    owner_user_id      CHAR(36)       NULL,         -- bon personnel issu de points
    active             BOOLEAN        NOT NULL DEFAULT TRUE,
    created_at         DATETIME(6)    NOT NULL,
    CONSTRAINT pk_promo_codes PRIMARY KEY (id),
    CONSTRAINT uk_promo_codes_code UNIQUE (code),
    CONSTRAINT ck_promo_type CHECK (discount_type IN ('PERCENT', 'AMOUNT')),
    CONSTRAINT ck_promo_value CHECK (discount_value > 0),
    CONSTRAINT ck_promo_percent CHECK (discount_type <> 'PERCENT' OR discount_value <= 50),
    CONSTRAINT ck_promo_dates CHECK (valid_until > valid_from),
    CONSTRAINT ck_promo_min_tier CHECK (min_tier IS NULL OR min_tier IN ('BRONZE', 'SILVER', 'GOLD')),
    CONSTRAINT ck_promo_max_uses CHECK (max_uses IS NULL OR max_uses > 0),
    CONSTRAINT ck_promo_max_per_user CHECK (max_uses_per_user > 0),
    CONSTRAINT ck_promo_used CHECK (used_count >= 0 AND (max_uses IS NULL OR used_count <= max_uses))
);

CREATE INDEX idx_promo_owner ON promo_codes (owner_user_id);

CREATE TABLE promo_usages (
    id              CHAR(36)       NOT NULL,
    promo_code_id   CHAR(36)       NOT NULL,
    user_id         CHAR(36)       NOT NULL,
    reservation_id  CHAR(36)       NOT NULL,
    amount_before   DECIMAL(12, 3) NOT NULL,
    discount        DECIMAL(12, 3) NOT NULL,
    amount_after    DECIMAL(12, 3) NOT NULL,
    used_at         DATETIME(6)    NOT NULL,
    CONSTRAINT pk_promo_usages PRIMARY KEY (id),
    CONSTRAINT fk_usages_code FOREIGN KEY (promo_code_id) REFERENCES promo_codes (id),
    -- une réservation n'utilise qu'un code, une seule fois (apply idempotent)
    CONSTRAINT uk_usages_reservation UNIQUE (reservation_id),
    CONSTRAINT ck_usages_amounts CHECK (discount >= 0 AND amount_after >= 0)
);

CREATE INDEX idx_usages_code_user ON promo_usages (promo_code_id, user_id);

-- Idempotence des messages RabbitMQ : un eventId déjà traité est ignoré.
CREATE TABLE processed_events (
    event_id      CHAR(36)    NOT NULL,
    type          VARCHAR(40) NOT NULL,
    processed_at  DATETIME(6) NOT NULL,
    CONSTRAINT pk_processed_events PRIMARY KEY (event_id)
);
