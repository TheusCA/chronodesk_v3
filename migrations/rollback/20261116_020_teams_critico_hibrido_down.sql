-- Rollback da migration 20261116_020_teams_critico_hibrido.sql (F9).
-- NAO e migration de deploy. Aplicar so DEPOIS de voltar o codigo (o codigo
-- do F9 grava pausas.limite_segundos ao finalizar a pausa).
--
-- 0. Checagens previas, antes de qualquer alteracao (erro 45000
--    "rollback_020 abortado: ..."; procedimento temporario
--    rollback_020_confere, que fica no banco depois de uma parada ate a
--    proxima execucao, que o recria e o apaga no fim):
--    a) a copia pausas_limite_bkp_020 ja existe (rollback anterior): um
--       segundo rollback nao tem o que copiar; para repetir, conferir a
--       copia, renomea-la (RENAME TABLE) e rodar de novo;
--    b) alguma linha usa 'critico' ou 'hibrido' (cadastro ou qualquer das
--       oito tabelas): estreitar o ENUM perderia o valor (D-F9-15). Nada e
--       trocado por script: a gestao volta esses cadastros pela tela antes.
-- 0b. Copia de id e limite_segundos das pausas com limite gravado
--    (pausas_limite_bkp_020; so ID, nunca nome). Fica: remover a mao depois
--    de conferir.
-- 1. pausas.limite_segundos sai.
-- 2. O ENUM de equipe volta ao de antes nas oito tabelas.
-- Os passos 1 e 2 conferem information_schema antes de alterar. O arquivo
-- inteiro NAO se repete depois de uma execucao com a 020 aplicada: a segunda
-- para no passo 0, de proposito. Sem a 020, nada muda e nenhuma copia e
-- criada.

SET time_zone = 'America/Sao_Paulo';

-- 0. Checagens previas
DROP PROCEDURE IF EXISTS rollback_020_confere;
DELIMITER //
CREATE PROCEDURE rollback_020_confere()
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pausas_limite_bkp_020') > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'rollback_020 abortado: ja existe pausas_limite_bkp_020. Nada foi alterado. Renomeie a copia e repita.';
    END IF;
    IF (SELECT COUNT(*) FROM funcionarios WHERE LOWER(TRIM(equipe)) IN ('critico', 'hibrido'))
     + (SELECT COUNT(*) FROM portal_schedule_rules WHERE team IN ('critico', 'hibrido'))
     + (SELECT COUNT(*) FROM portal_schedule_exceptions WHERE team IN ('critico', 'hibrido'))
     + (SELECT COUNT(*) FROM portal_pa_assignments WHERE team IN ('critico', 'hibrido'))
     + (SELECT COUNT(*) FROM portal_absences WHERE team IN ('critico', 'hibrido'))
     + (SELECT COUNT(*) FROM portal_shift_attachments WHERE team IN ('critico', 'hibrido'))
     + (SELECT COUNT(*) FROM portal_overtime_entries WHERE team IN ('critico', 'hibrido'))
     + (SELECT COUNT(*) FROM portal_time_adjustments WHERE team IN ('critico', 'hibrido'))
     + (SELECT COUNT(*) FROM portal_oncall_shifts WHERE team IN ('critico', 'hibrido')) > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'rollback_020 abortado: ha linhas com equipe critico ou hibrido. Nada foi alterado.';
    END IF;
END//
DELIMITER ;
CALL rollback_020_confere();
DROP PROCEDURE rollback_020_confere;

-- 0b. Copia do limite gravado (a checagem acima garante que nao existe)
SET @has_limite_segundos := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pausas' AND COLUMN_NAME = 'limite_segundos'
);
SET @copy_limite_sql := IF(
    @has_limite_segundos > 0,
    'CREATE TABLE pausas_limite_bkp_020 AS
        SELECT id, limite_segundos FROM pausas WHERE limite_segundos IS NOT NULL',
    'SELECT 1'
);
PREPARE copy_limite_stmt FROM @copy_limite_sql;
EXECUTE copy_limite_stmt;
DEALLOCATE PREPARE copy_limite_stmt;

-- 1. Coluna do limite
SET @drop_limite_sql := IF(
    @has_limite_segundos > 0,
    'ALTER TABLE pausas DROP COLUMN limite_segundos',
    'SELECT 1'
);
PREPARE drop_limite_stmt FROM @drop_limite_sql;
EXECUTE drop_limite_stmt;
DEALLOCATE PREPARE drop_limite_stmt;

-- 2. ENUM de equipe de volta (so onde o tipo tem 'critico')
DROP PROCEDURE IF EXISTS rollback_020_estreita_equipe;
DELIMITER //
CREATE PROCEDURE rollback_020_estreita_equipe(IN tabela VARCHAR(64), IN valores VARCHAR(200), IN nulidade VARCHAR(120))
BEGIN
    DECLARE tipo_atual TEXT DEFAULT NULL;
    SELECT COLUMN_TYPE INTO tipo_atual
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tabela AND COLUMN_NAME = 'team';
    IF tipo_atual IS NOT NULL AND LOCATE('''critico''', tipo_atual) > 0 THEN
        SET @rollback_020_sql := CONCAT('ALTER TABLE ', tabela, ' MODIFY COLUMN team ENUM(', valores, ') ', nulidade);
        PREPARE rollback_020_stmt FROM @rollback_020_sql;
        EXECUTE rollback_020_stmt;
        DEALLOCATE PREPARE rollback_020_stmt;
    END IF;
END//
DELIMITER ;
CALL rollback_020_estreita_equipe('portal_schedule_rules', '''n1'', ''n2'', ''lideranca''', 'NOT NULL');
CALL rollback_020_estreita_equipe('portal_schedule_exceptions', '''n1'', ''n2'', ''lideranca''', 'NOT NULL');
CALL rollback_020_estreita_equipe('portal_pa_assignments', '''n1'', ''n2'', ''lideranca''', 'NOT NULL');
CALL rollback_020_estreita_equipe('portal_absences', '''n1'', ''n2'', ''lideranca''', 'NOT NULL');
CALL rollback_020_estreita_equipe('portal_shift_attachments', '''n1'', ''n2'', ''lideranca''', 'NULL DEFAULT NULL COMMENT ''Equipe que ve o anexo; NULL = todas as equipes''');
CALL rollback_020_estreita_equipe('portal_overtime_entries', '''n1'', ''n2''', 'NOT NULL');
CALL rollback_020_estreita_equipe('portal_time_adjustments', '''n1'', ''n2''', 'NOT NULL');
CALL rollback_020_estreita_equipe('portal_oncall_shifts', '''n1'', ''n2''', 'NOT NULL');
DROP PROCEDURE rollback_020_estreita_equipe;
