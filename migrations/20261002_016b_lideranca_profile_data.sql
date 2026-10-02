-- ChronoDesk - normalizacao dos perfis do Lote 5 (016b, Lote 5b).
-- NAO e para antes do codigo: aplicar DEPOIS do git pull do Lote 5, com o
-- codigo novo no ar (o codigo antigo leria 'lideranca' como tecnico).
-- Exige a migration 016 (valor 'lideranca' no ENUM); sem ela, PARA antes de
-- qualquer alteracao com o erro "Table ... migration_016b_exige_016 doesn't
-- exist".
--
-- Passos (aprovados em 2026-10-02):
--   lideranca     access_role = 'admin' na equipe Lideranca -> 'lideranca'
--   admin_inativo access_role = 'admin', inativo, fora da Lideranca -> 'tecnico'
-- Admin ativo fora da Lideranca NAO e alterado (vira gestor na sessao pela
-- regra de transicao).
--
-- Antes de alterar, cada linha vai para funcionarios_perfil_016b (id, perfil
-- anterior, perfil novo, passo), base do 016b_down. INSERT IGNORE e UPDATE so
-- onde o perfil ainda e o anterior: rodar de novo nao muda nada nem gera
-- evento. Um evento CRITICAL por passo com linhas alteradas, com a contagem.
-- Datas: DEFAULT da coluna timestamp do audit_log (regra do A4).
--
-- Rollback: migrations/rollback/20261002_016b_lideranca_profile_data_down.sql.

SET time_zone = 'America/Sao_Paulo';

-- 0. Exige a 016
SET @access_role_type := (
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'funcionarios'
      AND COLUMN_NAME = 'access_role'
);
SET @abortar_016b_sql := IF(
    @access_role_type IS NULL OR LOCATE('''lideranca''', @access_role_type) = 0,
    'SELECT 1 FROM migration_016b_exige_016',
    'SELECT 1'
);
PREPARE abortar_016b_stmt FROM @abortar_016b_sql;
EXECUTE abortar_016b_stmt;
DEALLOCATE PREPARE abortar_016b_stmt;

-- 1. Registro para a reversao
CREATE TABLE IF NOT EXISTS funcionarios_perfil_016b (
    funcionario_id       INT NOT NULL,
    access_role_anterior VARCHAR(20) NOT NULL,
    access_role_novo     VARCHAR(20) NOT NULL,
    passo                VARCHAR(20) NOT NULL,
    PRIMARY KEY (funcionario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO funcionarios_perfil_016b (funcionario_id, access_role_anterior, access_role_novo, passo)
SELECT id, access_role, 'lideranca', 'lideranca'
FROM funcionarios
WHERE access_role = 'admin' AND equipe = 'lideranca';

INSERT IGNORE INTO funcionarios_perfil_016b (funcionario_id, access_role_anterior, access_role_novo, passo)
SELECT id, access_role, 'tecnico', 'admin_inativo'
FROM funcionarios
WHERE access_role = 'admin' AND ativo = 0 AND equipe <> 'lideranca';

-- 2. Lideranca
UPDATE funcionarios f
JOIN funcionarios_perfil_016b r ON r.funcionario_id = f.id
SET f.access_role = r.access_role_novo
WHERE r.passo = 'lideranca' AND f.access_role = r.access_role_anterior;
SET @lideranca_alterados := ROW_COUNT();

INSERT INTO audit_log (user_ip, username, action, details, severity)
SELECT 'migration', 'migration_016b', 'LIDERANCA_PERFIL_MIGRADO',
       CONCAT('016b: ', @lideranca_alterados, ' cadastro(s) admin da equipe Lideranca passaram a lideranca'),
       'CRITICAL'
FROM DUAL
WHERE @lideranca_alterados > 0;

-- 3. Admin inativo fora da Lideranca
UPDATE funcionarios f
JOIN funcionarios_perfil_016b r ON r.funcionario_id = f.id
SET f.access_role = r.access_role_novo
WHERE r.passo = 'admin_inativo' AND f.access_role = r.access_role_anterior AND f.ativo = 0;
SET @inativos_alterados := ROW_COUNT();

INSERT INTO audit_log (user_ip, username, action, details, severity)
SELECT 'migration', 'migration_016b', 'ADMIN_INATIVO_REBAIXADO',
       CONCAT('016b: ', @inativos_alterados, ' cadastro(s) admin inativo(s) fora da Lideranca passaram a tecnico'),
       'CRITICAL'
FROM DUAL
WHERE @inativos_alterados > 0;
