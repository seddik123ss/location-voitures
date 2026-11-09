-- Droits de complaint_svc sur complaint_db et complaint_db_test (tests PHPUnit).
-- Déjà dans infra/mysql/init.sql pour les nouvelles installations ; à lancer une fois si le volume MySQL existe déjà.
GRANT ALL PRIVILEGES ON `complaint_db%`.* TO 'complaint_svc'@'%';
FLUSH PRIVILEGES;
SHOW GRANTS FOR 'complaint_svc'@'%';
