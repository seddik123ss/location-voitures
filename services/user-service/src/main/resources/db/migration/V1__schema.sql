-- user-service : schéma initial
-- Identifiants UUID stockés en CHAR(36) (même texte que dans Keycloak et les autres services).
-- Les clés étrangères ne relient que des tables de user_db (jamais une autre base).

CREATE TABLE users (
    id          CHAR(36)     NOT NULL,          -- = "sub" Keycloak
    firstname   VARCHAR(100) NOT NULL,
    lastname    VARCHAR(100) NOT NULL,
    email       VARCHAR(255) NOT NULL,
    phone       VARCHAR(20)  NULL,
    role        VARCHAR(10)  NOT NULL,          -- CLIENT, AGENT, ADMIN
    active      BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at  DATETIME(6)  NOT NULL,
    updated_at  DATETIME(6)  NOT NULL,
    CONSTRAINT pk_users PRIMARY KEY (id),
    CONSTRAINT uk_users_email UNIQUE (email),
    CONSTRAINT ck_users_role CHECK (role IN ('CLIENT', 'AGENT', 'ADMIN'))
);

CREATE INDEX idx_users_role ON users (role);
CREATE INDEX idx_users_lastname ON users (lastname);

CREATE TABLE addresses (
    id        CHAR(36)     NOT NULL,
    street    VARCHAR(255) NOT NULL,
    city      VARCHAR(100) NOT NULL,
    zip_code  VARCHAR(10)  NOT NULL,
    user_id   CHAR(36)     NOT NULL,
    CONSTRAINT pk_addresses PRIMARY KEY (id),
    CONSTRAINT fk_addresses_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE driver_licenses (
    id           CHAR(36)    NOT NULL,
    number       VARCHAR(30) NOT NULL,
    category     VARCHAR(10) NOT NULL,          -- A, B, C, D...
    issue_date   DATE        NOT NULL,
    expiry_date  DATE        NOT NULL,
    verified     BOOLEAN     NOT NULL DEFAULT FALSE,
    user_id      CHAR(36)    NOT NULL,
    CONSTRAINT pk_driver_licenses PRIMARY KEY (id),
    CONSTRAINT uk_driver_licenses_number UNIQUE (number),
    CONSTRAINT uk_driver_licenses_user UNIQUE (user_id),   -- un permis par utilisateur
    CONSTRAINT fk_driver_licenses_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
