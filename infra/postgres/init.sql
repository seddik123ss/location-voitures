CREATE USER payment_svc WITH PASSWORD 'payment_pwd';
CREATE DATABASE payment_db OWNER payment_svc;
CREATE USER contract_svc WITH PASSWORD 'contract_pwd';
CREATE DATABASE contract_db OWNER contract_svc;
CREATE USER ml_svc WITH PASSWORD 'ml_pwd';
CREATE DATABASE ml_db OWNER ml_svc;
