-- Données de démo : mêmes UUID que Keycloak (docs/demo-ids.md).
-- Prénoms, noms et emails repris du realm infra/keycloak/location-voitures-realm.json.
-- client2 a un permis expiré le 2026-06-30 (pour tester le 422).

INSERT INTO users (id, firstname, lastname, email, phone, role, active, created_at, updated_at) VALUES
('f4d1bab3-468d-428d-b02c-b44341430bfd', 'Client', 'Un',   'client1@test.tn', '+21620111111', 'CLIENT', TRUE, '2026-11-01 08:00:00', '2026-11-01 08:00:00'),
('611f32de-ce7b-4927-b1c4-d113b6479d59', 'Client', 'Deux', 'client2@test.tn', '+21620222222', 'CLIENT', TRUE, '2026-11-01 08:00:00', '2026-11-01 08:00:00'),
('11c9b774-201e-4a8c-be43-6b084b52f4c7', 'Agent',  'Un',   'agent1@test.tn',  '+21671333333', 'AGENT',  TRUE, '2026-11-01 08:00:00', '2026-11-01 08:00:00'),
('fd797399-4d3a-433d-afdd-37b0dd3437cb', 'Admin',  'Un',   'admin1@test.tn',  '+21671444444', 'ADMIN',  TRUE, '2026-11-01 08:00:00', '2026-11-01 08:00:00');

INSERT INTO addresses (id, street, city, zip_code, user_id) VALUES
('fa1bace4-042a-4ce2-be5f-d4c3cb4b98da', '12 Rue de Marseille',          'Tunis', '1000', 'f4d1bab3-468d-428d-b02c-b44341430bfd'),
('3cbcb9b1-03f2-422b-b19e-4514c20ecc2e', '5 Avenue Leopold Senghor',     'Sousse', '4000', '611f32de-ce7b-4927-b1c4-d113b6479d59'),
('388d47ac-80bc-4bba-b446-197fc00b8d48', 'Avenue Habib Bourguiba',       'Tunis', '1001', '11c9b774-201e-4a8c-be43-6b084b52f4c7');

INSERT INTO driver_licenses (id, number, category, issue_date, expiry_date, verified, user_id) VALUES
('edc2e0e3-709d-4caa-b617-53e4c5f128cf', 'TN-2020-000111', 'B', '2020-03-15', '2030-03-15', TRUE, 'f4d1bab3-468d-428d-b02c-b44341430bfd'),
('3499a658-97ae-4e27-9e1c-3b2d24904828', 'TN-2016-000222', 'B', '2016-06-30', '2026-06-30', TRUE, '611f32de-ce7b-4927-b1c4-d113b6479d59');
