-- ChronoDesk - REGISTRO DA DATA DE CORTE do motor de presenca (Parte B, F5;
-- desenho, secao 9.7, D1 a D3 e D14).
--
-- NAO E MIGRATION DE DEPLOY: e o passo de registro do roteiro "Deploy que
-- traz o F5". Rodar UMA vez, no dia do deploy, depois da 019 e da recarga do
-- PHP-FPM. Sem @corte e @autor definidos antes deste arquivo, PARA sem
-- alterar nada (aplicado por engano como migration, nao faz nada).
--
-- Data (D2): o 1o dia da competencia SEGUINTE ao deploy (competencia de 16
-- a 15), que pode estar no futuro; assim nenhuma competencia do relatorio ou
-- do CSV mistura as duas regras. Para conferir o valor no dia do deploy (so
-- le; o resultado e a data a passar em @corte):
--   SET time_zone = 'America/Sao_Paulo'; SELECT IF(DAYOFMONTH(CURDATE()) >= 16, DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-16'), DATE_FORMAT(CURDATE(), '%Y-%m-16')) AS corte;
--
-- Para (SIGNAL 45000, nada alterado) se: a tabela da 019 nao existe; @corte
-- ou @autor faltam; @corte nao esta no formato AAAA-MM-DD; o dia nao e 16;
-- o mes e invalido; a data e anterior ao dia em que roda (D3: nunca
-- reescrever dia passado); ja existe corte (D3: imutavel; trocar so com o
-- rollback da 019 e registro novo, com aprovacao).
-- Recusado, o procedimento registrar_corte_019 fica no banco (o DROP do fim
-- nao roda depois do erro): a proxima execucao o recria; para limpar,
-- DROP PROCEDURE IF EXISTS registrar_corte_019 (a verificacao conta).
-- Grava a linha (cutoff_date e registered_by) e o evento
-- PRESENCE_CUTOFF_REGISTERED (CRITICAL) no audit_log com a data, na mesma
-- transacao. O "quando" e a coluna timestamp do audit_log, pelo DEFAULT dela,
-- como na 016b. "Hoje" (so na checagem de data passada) e a data do MySQL no
-- fuso do PHP, depois do SET time_zone abaixo (regra do A4).
--
-- Uso (servidor, a partir de /var/www/chronodesk; <ref> e o commit do F5):
--   read -rsp 'Senha root do MySQL: ' P; echo; export P; { printf "SET @corte := '%s'; SET @autor := '%s';\n" 'AAAA-MM-16' '<login do operador>'; sudo git show <ref>:migrations/20261013_019_registrar_corte.sql; } | docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot sistema_pausas; unset P
--
-- Ensaio (variantes b e c da 019; a 019 entra como PREPARO e o arquivo do
-- ensaio leva @corte e @autor na frente deste):
--   (b) corte valido, 2x: a 1a grava (cortes = 1, eventos_registro = 1); a 2a
--       PARA com "corte ja registrado"; o 1o rollback copia a linha e remove
--       a tabela (eventos_remocao = 1); o 2o rollback para de proposito:
--   { printf "SET @corte := '2099-12-16'; SET @autor := 'qa.ensaio';\n"; cat migrations/20261013_019_registrar_corte.sql; } | sudo tee /root/registro_f5.sql > /dev/null
--   sudo env PREPARO=migrations/20261013_019_presence_cutoff.sql \
--     VERIFICA=migrations/verificacao/20261013_019_presence_cutoff_check.sql \
--     bash scripts/ensaio-migration.sh f5_registro /root/registro_f5.sql \
--     migrations/rollback/20261013_019_presence_cutoff_down.sql audit_log
--   (c) data passada: as duas execucoes PARAM com "data anterior a hoje"
--       (cortes = 0); o rollback remove a tabela vazia:
--   { printf "SET @corte := '2020-01-16'; SET @autor := 'qa.ensaio';\n"; cat migrations/20261013_019_registrar_corte.sql; } | sudo tee /root/registro_f5_passado.sql > /dev/null
--   (mesmo comando, com f5_passado e /root/registro_f5_passado.sql)
--   As demais recusas (sem @corte, dia diferente de 16, mes invalido) seguem o
--   mesmo caminho: troque so o printf.

SET time_zone = 'America/Sao_Paulo';

DROP PROCEDURE IF EXISTS registrar_corte_019;
DELIMITER //
CREATE PROCEDURE registrar_corte_019(IN corte_texto VARCHAR(40), IN autor VARCHAR(100))
BEGIN
    DECLARE ano INT;
    DECLARE mes INT;
    DECLARE corte DATE;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_presence_cutoff') = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'registro_019 abortado: aplique a migration 019 antes. Nada foi alterado.';
    END IF;
    IF corte_texto IS NULL OR autor IS NULL OR TRIM(autor) = '' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'registro_019 abortado: defina @corte e @autor antes do arquivo. Nada foi alterado.';
    END IF;
    IF corte_texto NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'registro_019 abortado: @corte fora do formato AAAA-MM-DD. Nada foi alterado.';
    END IF;
    IF SUBSTRING(corte_texto, 9, 2) <> '16' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'registro_019 abortado: o corte e sempre dia 16 (inicio de competencia). Nada foi alterado.';
    END IF;
    SET ano = CAST(SUBSTRING(corte_texto, 1, 4) AS UNSIGNED);
    SET mes = CAST(SUBSTRING(corte_texto, 6, 2) AS UNSIGNED);
    IF mes < 1 OR mes > 12 OR ano < 2000 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'registro_019 abortado: mes ou ano invalido em @corte. Nada foi alterado.';
    END IF;
    -- Dia 16 existe em todo mes: a data e montada sem conversao de texto.
    SET corte = MAKEDATE(ano, 1) + INTERVAL (mes - 1) MONTH + INTERVAL 15 DAY;
    IF corte < CURDATE() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'registro_019 abortado: data anterior a hoje (nunca reescrever dia passado). Nada foi alterado.';
    END IF;
    IF (SELECT COUNT(*) FROM portal_presence_cutoff) > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'registro_019 abortado: corte ja registrado (imutavel). Nada foi alterado.';
    END IF;

    START TRANSACTION;
    INSERT INTO portal_presence_cutoff (id, cutoff_date, registered_by)
    VALUES (1, corte, TRIM(autor));
    INSERT INTO audit_log (user_ip, username, action, details, severity)
    VALUES ('migration', 'registro_019', 'PRESENCE_CUTOFF_REGISTERED',
            CONCAT('019: corte do motor de presenca em ', DATE_FORMAT(corte, '%Y-%m-%d')), 'CRITICAL');
    COMMIT;
END//
DELIMITER ;
CALL registrar_corte_019(@corte, @autor);
DROP PROCEDURE registrar_corte_019;

SELECT cutoff_date AS corte_registrado FROM portal_presence_cutoff;
