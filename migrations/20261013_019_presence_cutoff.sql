-- ChronoDesk - data de corte do motor de presenca (Parte B, F5; desenho,
-- secoes 0.6, 6 e 9.7). Plano aprovado em 2026-10-13 (D1 a D3, D14).
--
-- 1. portal_presence_cutoff: no maximo uma linha (CHECK id = 1) com a data a
--    partir da qual Calendario, relatorio e CSV usam o motor; antes dela, o
--    calculo antigo, sem nenhuma mudanca. A data e sempre dia 16, inicio de
--    competencia (D2: competencia de 16 a 15), e quem registrou fica em
--    registered_by. O quando fica no audit_log (PRESENCE_CUTOFF_REGISTERED).
-- 2. Gatilhos trg_presence_cutoff_no_update e trg_presence_cutoff_no_delete
--    (BEFORE UPDATE e BEFORE DELETE): sempre param com SIGNAL 45000 "corte
--    imutavel: use o rollback da 019" (D3 no banco, nao so no script de
--    registro; aprovacao do commit 1, 2026-10-14). INSERT fica livre: o
--    registro grava a linha e o CHECK (id = 1) impede a segunda. Trocar o
--    corte so pelo rollback, que remove a tabela com DROP TABLE (nao passa
--    pelos gatilhos; eles saem junto com a tabela). REPLACE e INSERT ... ON
--    DUPLICATE KEY UPDATE sobre a linha tambem param (passam pelo DELETE ou
--    pelo UPDATE); TRUNCATE nao passa por gatilho, mas exige o privilegio
--    DROP, que o usuario da aplicacao nao tem.
-- A migration so cria a tabela, VAZIA: sem linha, a aplicacao usa o calculo
-- antigo em todas as datas (nada muda ate o registro). A linha e gravada so
-- pelo script 20261013_019_registrar_corte.sql, no dia do deploy, depois da
-- recarga, com a data explicita (D14); a aplicacao so le.
-- Idempotente: a tabela, pela checagem em information_schema.TABLES; os
-- gatilhos, por CREATE TRIGGER IF NOT EXISTS, porque o MySQL nao aceita
-- CREATE TRIGGER em PREPARE (erro 1295) nem dentro de procedimento (erro
-- 1303), e por isso o IF sobre information_schema.TRIGGERS nao e possivel
-- aqui; a verificacao confere os dois em information_schema.TRIGGERS
-- (gatilhos_019 = 2). Exige MySQL 8.0.29 ou mais novo (IF NOT EXISTS em
-- CREATE TRIGGER); conferir com SELECT VERSION() antes do ensaio.
--
-- Aplicar ANTES do git merge do F5. O codigo anterior nao le a tabela.
--
-- Ensaio (no servidor, a partir de /var/www/chronodesk), tres variantes;
-- audit_log entra como tabela extra (o registro e o rollback auditam):
--   (a) migration 2x sem erro; 1o rollback volta; 2o para de proposito:
--   sudo env VERIFICA=migrations/verificacao/20261013_019_presence_cutoff_check.sql \
--     bash scripts/ensaio-migration.sh f5 \
--     migrations/20261013_019_presence_cutoff.sql \
--     migrations/rollback/20261013_019_presence_cutoff_down.sql audit_log
--   (b) registro do corte 2x (o 2o para) e rollback com o corte registrado e
--   (c) registro com data passada recusado: cabecalho de
--       20261013_019_registrar_corte.sql
--
-- Rollback: migrations/rollback/20261013_019_presence_cutoff_down.sql.
-- Verificacao: migrations/verificacao/20261013_019_presence_cutoff_check.sql.

SET time_zone = 'America/Sao_Paulo';

-- 1. Tabela do corte
SET @has_cutoff_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_presence_cutoff'
);
SET @create_cutoff_sql := IF(
    @has_cutoff_table = 0,
    'CREATE TABLE portal_presence_cutoff (
        id TINYINT UNSIGNED NOT NULL COMMENT ''Sempre 1: no maximo um corte'',
        cutoff_date DATE NOT NULL COMMENT ''Primeiro dia do motor no Calendario e nos relatorios (F5); dia 16, inicio de competencia'',
        registered_by VARCHAR(100) NOT NULL COMMENT ''Login de quem registrou o corte no deploy'',
        PRIMARY KEY (id),
        CONSTRAINT ck_presence_cutoff_single CHECK (id = 1),
        CONSTRAINT ck_presence_cutoff_day CHECK (DAYOFMONTH(cutoff_date) = 16)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Data de corte do motor de presenca (Parte B, F5). Gravada so pelo script de registro''',
    'SELECT 1'
);
PREPARE create_cutoff_stmt FROM @create_cutoff_sql;
EXECUTE create_cutoff_stmt;
DEALLOCATE PREPARE create_cutoff_stmt;

-- 2. Gatilhos da imutabilidade (D3): UPDATE e DELETE sempre param. Corpo de
-- uma instrucao so, sem DELIMITER.
CREATE TRIGGER IF NOT EXISTS trg_presence_cutoff_no_update
    BEFORE UPDATE ON portal_presence_cutoff
    FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'corte imutavel: use o rollback da 019';

CREATE TRIGGER IF NOT EXISTS trg_presence_cutoff_no_delete
    BEFORE DELETE ON portal_presence_cutoff
    FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'corte imutavel: use o rollback da 019';
