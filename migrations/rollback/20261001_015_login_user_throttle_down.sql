-- Rollback da migration 20261001_015_login_user_throttle.sql (SEC-03).
-- NAO e migration de deploy: aplicar somente no rollback do Lote 7, DEPOIS de
-- voltar o codigo para a versao anterior. Com o codigo do Lote 7 no ar e sem
-- a tabela, todo login e recusado com 503.
--
-- A tabela so guarda contadores temporarios (janela padrao de 15 min);
-- apaga-la nao perde dado de negocio. Idempotente.

SET time_zone = 'America/Sao_Paulo';

DROP TABLE IF EXISTS login_user_throttle;
