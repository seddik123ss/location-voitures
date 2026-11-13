-- Données de démo (cahier des charges, section 10) : mêmes UUID que Keycloak et docs/demo-ids.md.
-- client1 : GOLD, cumul 1 640, solde 1 240 (400 points convertis en un bon LOY-DEMO40 de 40 TND).
-- client2 : BRONZE, cumul 180, solde 180.
-- agent1 et admin1 n'ont pas de compte de fidélité (réservé aux clients).

INSERT INTO loyalty_accounts (user_id, balance, lifetime_points, tier, status, created_at, updated_at) VALUES
('f4d1bab3-468d-428d-b02c-b44341430bfd', 1240, 1640, 'GOLD',   'ACTIVE', '2026-01-10 09:00:00', '2026-11-05 10:00:00'),
('611f32de-ce7b-4927-b1c4-d113b6479d59',  180,  180, 'BRONZE', 'ACTIVE', '2026-06-01 09:00:00', '2026-07-15 10:00:00');

-- Historique cohérent avec les soldes : somme des points = solde ; somme des gains = cumul.
INSERT INTO point_transactions (id, user_id, type, points, reason, reservation_id, created_at) VALUES
('5c1d7a0e-3b8f-4e62-9a41-0d2f6b8c1e01', 'f4d1bab3-468d-428d-b02c-b44341430bfd', 'WELCOME',  100, 'Points de bienvenue',                      NULL, '2026-01-10 09:00:00'),
('5c1d7a0e-3b8f-4e62-9a41-0d2f6b8c1e02', 'f4d1bab3-468d-428d-b02c-b44341430bfd', 'EARN',     640, 'Location terminée (historique de démo)',   'a0000001-0000-4000-8000-000000000001', '2026-03-02 18:00:00'),
('5c1d7a0e-3b8f-4e62-9a41-0d2f6b8c1e03', 'f4d1bab3-468d-428d-b02c-b44341430bfd', 'EARN',     900, 'Location terminée (historique de démo)',   'a0000001-0000-4000-8000-000000000002', '2026-08-20 18:00:00'),
('5c1d7a0e-3b8f-4e62-9a41-0d2f6b8c1e04', 'f4d1bab3-468d-428d-b02c-b44341430bfd', 'REDEEM',  -400, 'Conversion en bon LOY-DEMO40',             NULL, '2026-11-05 10:00:00'),
('5c1d7a0e-3b8f-4e62-9a41-0d2f6b8c1e05', '611f32de-ce7b-4927-b1c4-d113b6479d59', 'WELCOME',  100, 'Points de bienvenue',                      NULL, '2026-06-01 09:00:00'),
('5c1d7a0e-3b8f-4e62-9a41-0d2f6b8c1e06', '611f32de-ce7b-4927-b1c4-d113b6479d59', 'ADJUST',    80, 'Geste commercial (données de démo)',       NULL, '2026-07-15 10:00:00');

INSERT INTO promo_codes (id, code, discount_type, discount_value, min_amount, min_tier, valid_from, valid_until,
                         max_uses, max_uses_per_user, used_count, owner_user_id, active, created_at) VALUES
-- BIENVENUE10 : 10 %, valable jusqu'au 2027-12-31 -> succès
('0a76460c-0f85-475e-9744-e5d95b3f32b9', 'BIENVENUE10', 'PERCENT', 10.000, NULL,    NULL,   '2026-01-01', '2027-12-31', NULL, 1, 0,   NULL, TRUE, '2026-01-01 08:00:00'),
-- ETE2026 : 15 %, expiré le 2026-09-30 -> 422
('ef44685d-8991-42c6-92bc-8a4e576244ae', 'ETE2026',     'PERCENT', 15.000, NULL,    NULL,   '2026-06-01', '2026-09-30', NULL, 1, 0,   NULL, TRUE, '2026-05-25 08:00:00'),
-- GOLD50 : 50 TND réservés aux GOLD -> client1 OK, client2 403
('bb097bb8-276b-4cb9-be20-ecb62740356e', 'GOLD50',      'AMOUNT',  50.000, NULL,    'GOLD', '2026-01-01', '2027-12-31', NULL, 1, 0,   NULL, TRUE, '2026-01-01 08:00:00'),
-- FLASH100 : 20 %, 100 utilisations sur 100 -> 409
('4332ff93-5d22-4f6e-be31-13c015b1cfa4', 'FLASH100',    'PERCENT', 20.000, NULL,    NULL,   '2026-10-01', '2027-12-31', 100,  1, 100, NULL, TRUE, '2026-10-01 08:00:00'),
-- WEEKEND : 30 TND à partir de 200 TND -> 422 sous ce montant
('98b85c18-198b-411c-9025-c480491dbe91', 'WEEKEND',     'AMOUNT',  30.000, 200.000, NULL,   '2026-01-01', '2027-12-31', NULL, 1, 0,   NULL, TRUE, '2026-01-01 08:00:00'),
-- Bon personnel de client1 (400 points convertis), non utilisé, valable 90 jours
('7e3a9c21-4b5d-4f8e-8a17-2c6d9e0f1b40', 'LOY-DEMO40',  'AMOUNT',  40.000, NULL,    NULL,   '2026-11-05', '2027-02-03', 1,    1, 0,   'f4d1bab3-468d-428d-b02c-b44341430bfd', TRUE, '2026-11-05 10:00:00');
