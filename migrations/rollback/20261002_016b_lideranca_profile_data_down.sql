-- Rollback do 016b (Lote 5b): devolve o perfil anterior so as linhas
-- registradas em funcionarios_perfil_016b que ainda estao com o perfil que o
-- 016b gravou. Quem foi promovido ou alterado depois nao e tocado.
-- Aplicar com o codigo novo ainda no ar, ANTES de voltar o codigo.
-- Sem a tabela de registro, nao faz nada. A tabela e mantida: o 016b pode
-- ser reaplicado depois e reusa o mesmo registro. Idempotente. Um evento
-- CRITICAL com a contagem, so se alguma linha mudou.

SET time_zone = 'America/Sao_Paulo';

SET @tem_registro := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'funcionarios_perfil_016b'
);
SET @reverter_016b_sql := IF(
    @tem_registro > 0,
    'UPDATE funcionarios f
        JOIN funcionarios_perfil_016b r ON r.funcionario_id = f.id
        SET f.access_role = r.access_role_anterior
        WHERE f.access_role = r.access_role_novo',
    'SELECT 1'
);
PREPARE reverter_016b_stmt FROM @reverter_016b_sql;
EXECUTE reverter_016b_stmt;
SET @revertidos := IF(@tem_registro > 0, ROW_COUNT(), 0);
DEALLOCATE PREPARE reverter_016b_stmt;

INSERT INTO audit_log (user_ip, username, action, details, severity)
SELECT 'migration', 'migration_016b_down', 'LIDERANCA_PERFIL_REVERTIDO',
       CONCAT('016b_down: ', @revertidos, ' cadastro(s) voltaram ao perfil anterior'),
       'CRITICAL'
FROM DUAL
WHERE @revertidos > 0;
