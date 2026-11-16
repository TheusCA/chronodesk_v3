-- ChronoDesk - equipes Critico e Hibrido e o limite aplicado da pausa (F9;
-- desenho principal, 9.15, aprovado em 2026-11-16; 11.3; D-F10-1).
-- Idempotente: cada passo confere information_schema antes de alterar.
-- Nenhuma linha de dado e alterada: so a estrutura.
--
-- 1. O ENUM de equipe ganha 'critico' e 'hibrido' NO FIM (os valores de hoje
--    mantem a posicao) em oito tabelas: portal_schedule_rules,
--    portal_schedule_exceptions, portal_pa_assignments, portal_absences,
--    portal_shift_attachments (n1, n2, lideranca; a ultima aceita NULL =
--    todas) e portal_overtime_entries, portal_time_adjustments,
--    portal_oncall_shifts (n1, n2). A legada portal_pa_map fica como esta
--    (D-F9-4). funcionarios.equipe e pausas.equipe sao VARCHAR(10): ja cabem.
-- 2. pausas.limite_segundos INT UNSIGNED NULL: o limite aplicado a pausa,
--    gravado ao finalizar (D-F9-7). As pausas de hoje ficam com NULL e as
--    Metricas usam a configuracao atual para elas.
--
-- Algoritmo explicito por tabela: o MySQL para em vez de copiar sem aviso.
-- No MySQL 8.0.46, sete tabelas e a coluna nova so mudam o metadado
-- (INSTANT); a portal_absences tem CHECK (ck_absence_period e
-- ck_absence_range) e so aceita COPY: a tabela e copiada (escrita bloqueada
-- durante a copia), por isso a 020 roda com a manutencao ligada.
--
-- Pode ser aplicada ANTES do git merge (o codigo anterior nao grava os
-- valores novos nem le a coluna) e DEVE ser aplicada antes dele: o codigo do
-- F9 grava limite_segundos ao finalizar a pausa.
-- A data nao e usada; SET time_zone segue a regra do A4 para sessao manual.
--
-- Ensaio (no servidor, a partir de /var/www/chronodesk; ausencias e pausas
-- copiadas com os dados, as outras seis tabelas com ENUM so na estrutura):
--   sudo env DADOS=portal_absences,pausas \
--     VERIFICA=migrations/verificacao/20261116_020_teams_critico_hibrido_check.sql \
--     bash scripts/ensaio-migration.sh f9_020 \
--     migrations/20261116_020_teams_critico_hibrido.sql \
--     migrations/rollback/20261116_020_teams_critico_hibrido_down.sql \
--     portal_schedule_rules,portal_schedule_exceptions,portal_pa_assignments,portal_shift_attachments,portal_overtime_entries,portal_time_adjustments,portal_oncall_shifts,audit_log
--
-- Rollback: migrations/rollback/20261116_020_teams_critico_hibrido_down.sql.
-- Verificacao: migrations/verificacao/20261116_020_teams_critico_hibrido_check.sql.

SET time_zone = 'America/Sao_Paulo';

-- 1. ENUM de equipe (procedimento temporario: um ALTER por tabela, so se o
--    tipo ainda nao tem 'critico'; recriado a cada execucao e apagado no fim)
DROP PROCEDURE IF EXISTS migration_020_amplia_equipe;
DELIMITER //
CREATE PROCEDURE migration_020_amplia_equipe(IN tabela VARCHAR(64), IN valores VARCHAR(200), IN nulidade VARCHAR(120), IN algoritmo VARCHAR(10))
BEGIN
    DECLARE tipo_atual TEXT DEFAULT NULL;
    SELECT COLUMN_TYPE INTO tipo_atual
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tabela AND COLUMN_NAME = 'team';
    IF tipo_atual IS NOT NULL AND LOCATE('''critico''', tipo_atual) = 0 THEN
        SET @migration_020_sql := CONCAT('ALTER TABLE ', tabela, ' MODIFY COLUMN team ENUM(', valores, ') ', nulidade, ', ALGORITHM=', algoritmo);
        PREPARE migration_020_stmt FROM @migration_020_sql;
        EXECUTE migration_020_stmt;
        DEALLOCATE PREPARE migration_020_stmt;
    END IF;
END//
DELIMITER ;
CALL migration_020_amplia_equipe('portal_schedule_rules', '''n1'', ''n2'', ''lideranca'', ''critico'', ''hibrido''', 'NOT NULL', 'INSTANT');
CALL migration_020_amplia_equipe('portal_schedule_exceptions', '''n1'', ''n2'', ''lideranca'', ''critico'', ''hibrido''', 'NOT NULL', 'INSTANT');
CALL migration_020_amplia_equipe('portal_pa_assignments', '''n1'', ''n2'', ''lideranca'', ''critico'', ''hibrido''', 'NOT NULL', 'INSTANT');
CALL migration_020_amplia_equipe('portal_absences', '''n1'', ''n2'', ''lideranca'', ''critico'', ''hibrido''', 'NOT NULL', 'COPY');
CALL migration_020_amplia_equipe('portal_shift_attachments', '''n1'', ''n2'', ''lideranca'', ''critico'', ''hibrido''', 'NULL DEFAULT NULL COMMENT ''Equipe que ve o anexo; NULL = todas as equipes''', 'INSTANT');
CALL migration_020_amplia_equipe('portal_overtime_entries', '''n1'', ''n2'', ''critico'', ''hibrido''', 'NOT NULL', 'INSTANT');
CALL migration_020_amplia_equipe('portal_time_adjustments', '''n1'', ''n2'', ''critico'', ''hibrido''', 'NOT NULL', 'INSTANT');
CALL migration_020_amplia_equipe('portal_oncall_shifts', '''n1'', ''n2'', ''critico'', ''hibrido''', 'NOT NULL', 'INSTANT');
DROP PROCEDURE migration_020_amplia_equipe;

-- 2. Limite aplicado da pausa
SET @has_limite_segundos := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pausas' AND COLUMN_NAME = 'limite_segundos'
);
SET @add_limite_segundos_sql := IF(
    @has_limite_segundos = 0,
    'ALTER TABLE pausas
        ADD COLUMN limite_segundos INT UNSIGNED NULL
        COMMENT ''Limite aplicado a pausa (s), gravado ao finalizar (F9); NULL = sem limite registrado''
        AFTER alerta_20min,
        ALGORITHM=INSTANT',
    'SELECT 1'
);
PREPARE add_limite_segundos_stmt FROM @add_limite_segundos_sql;
EXECUTE add_limite_segundos_stmt;
DEALLOCATE PREPARE add_limite_segundos_stmt;
