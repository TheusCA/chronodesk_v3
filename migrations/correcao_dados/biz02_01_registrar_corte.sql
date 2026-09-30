-- ============================================================================
-- BIZ-02 (fuso horario) - 1 de 4: REGISTRAR O CORTE
--
-- NAO E MIGRATION DE DEPLOY. Nao aplicar junto com as migrations numeradas.
-- Procedimento completo: docs/FUSO_HORARIO_BIZ02.md.
--
-- O que faz: fotografa, em manutencao_biz02_datetime, o valor de cada coluna
-- DATETIME que foi gravada com o relogio do MySQL (NOW(), CURRENT_TIMESTAMP ou
-- DEFAULT CURRENT_TIMESTAMP) enquanto a sessao estava em UTC. NAO ALTERA NENHUM
-- DADO DA APLICACAO: cria duas tabelas de manutencao e copia valores para elas.
--
-- Por que existe: depois que o codigo corrigido entra, as linhas novas ja sao
-- gravadas no fuso certo. A correcao (script 3) precisa saber exatamente quais
-- linhas sao anteriores. Esta fotografia e essa lista, e tambem e a copia de
-- seguranca usada pelo rollback (script 4).
--
-- QUANDO RODAR: na mesma janela de manutencao do deploy do codigo corrigido,
-- logo depois do `git pull` e ANTES de sair da manutencao e de qualquer
-- validacao que grave no banco. Rodar mais tarde incluiria linhas ja corretas.
--
-- Idempotente: com o corte ja registrado, nao faz nada.
--
-- Guardas (o script nao fotografa nada se alguma disparar):
--   G1  existe linha no formato novo (valor igual ao updated_at): o codigo
--       corrigido ja gravou dados antes do corte;
--   G2  o audit_log tem um salto para tras de mais de 2h30 na ordem dos ids:
--       sinal de que o fuso de gravacao mudou no meio do historico;
--   G3  o fuso padrao do servidor MySQL nao e UTC: a premissa de que os valores
--       foram gravados em UTC (3 h adiante) deixa de valer.
-- Para seguir mesmo assim, depois de analisar a causa: @ignorar_guardas = 1.
--
-- Uso (banco de producao ou, para ensaio, o banco de teste de restauracao):
--   mysql <BANCO> < biz02_01_registrar_corte.sql
-- ============================================================================

SET @ignorar_guardas := 0;

-- Medido ANTES de trocar o fuso desta sessao: distancia, em minutos, entre o
-- fuso padrao do servidor e UTC. Esperado 0.
SET @g3_servidor_fora_de_utc := ABS(TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()));
SET @fuso_servidor := CONVERT(CONCAT(@@global.time_zone, '/', @@system_time_zone) USING utf8mb4);

-- TIMESTAMP e lido no fuso da sessao; os DATETIME abaixo estao em UTC literal.
-- Deslocamento fixo para o script nao depender das tabelas de fuso.
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS manutencao_marcadores (
    chave VARCHAR(64) NOT NULL,
    registrado_em DATETIME NOT NULL,
    detalhe VARCHAR(1000) DEFAULT NULL,
    PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manutencao_biz02_datetime (
    tabela VARCHAR(64) NOT NULL,
    coluna VARCHAR(64) NOT NULL,
    linha_id BIGINT NOT NULL,
    linha_chave VARCHAR(100) NOT NULL DEFAULT '',
    valor_utc DATETIME NOT NULL,
    corrigido_em DATETIME DEFAULT NULL,
    PRIMARY KEY (tabela, coluna, linha_id, linha_chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ja_registrado := (SELECT COUNT(*) FROM manutencao_marcadores WHERE chave = 'biz02_corte');

SET @g1_formato_novo :=
      (SELECT COUNT(*) FROM portal_overtime_entries WHERE approved_at IS NOT NULL AND approved_at = updated_at)
    + (SELECT COUNT(*) FROM portal_time_adjustments WHERE approved_at IS NOT NULL AND approved_at = updated_at)
    + (SELECT COUNT(*) FROM portal_pa_assignments   WHERE deleted_at  IS NOT NULL AND deleted_at  = updated_at);

SET @g2_salto_auditoria := (
    SELECT COUNT(*)
    FROM (
        SELECT `timestamp` AS valor,
               MAX(`timestamp`) OVER (ORDER BY id ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING) AS maior_anterior
        FROM audit_log
    ) AS ordenado
    WHERE valor < maior_anterior - INTERVAL 150 MINUTE
);

SET @pode_registrar := IF(
    @ja_registrado = 0
        AND (@ignorar_guardas = 1
             OR (@g1_formato_novo = 0 AND @g2_salto_auditoria = 0 AND @g3_servidor_fora_de_utc = 0)),
    1,
    0
);

START TRANSACTION;

-- Gravadas sempre pelo relogio do MySQL: todas as linhas.
INSERT INTO manutencao_biz02_datetime (tabela, coluna, linha_id, linha_chave, valor_utc)
SELECT 'audit_log', 'timestamp', id, '', `timestamp`
FROM audit_log
WHERE `timestamp` IS NOT NULL AND @pode_registrar = 1;

INSERT INTO manutencao_biz02_datetime (tabela, coluna, linha_id, linha_chave, valor_utc)
SELECT 'portal_notification_reads', 'read_at', notification_id, username, read_at
FROM portal_notification_reads
WHERE @pode_registrar = 1;

INSERT INTO manutencao_biz02_datetime (tabela, coluna, linha_id, linha_chave, valor_utc)
SELECT 'pause_approval_requests', 'decided_at', id, '', decided_at
FROM pause_approval_requests
WHERE decided_at IS NOT NULL AND @pode_registrar = 1;

-- Gravadas pelo relogio do MySQL na mesma instrucao que atualiza updated_at
-- (TIMESTAMP, instante verdadeiro). A linha so entra se o valor esta exatamente
-- 3 h adiante do updated_at. O que nao bater fica de fora e aparece no script 2.
INSERT INTO manutencao_biz02_datetime (tabela, coluna, linha_id, linha_chave, valor_utc)
SELECT 'portal_overtime_entries', 'approved_at', id, '', approved_at
FROM portal_overtime_entries
WHERE approved_at = updated_at + INTERVAL 3 HOUR AND @pode_registrar = 1;

INSERT INTO manutencao_biz02_datetime (tabela, coluna, linha_id, linha_chave, valor_utc)
SELECT 'portal_time_adjustments', 'approved_at', id, '', approved_at
FROM portal_time_adjustments
WHERE approved_at = updated_at + INTERVAL 3 HOUR AND @pode_registrar = 1;

INSERT INTO manutencao_biz02_datetime (tabela, coluna, linha_id, linha_chave, valor_utc)
SELECT 'portal_pa_assignments', 'deleted_at', id, '', deleted_at
FROM portal_pa_assignments
WHERE deleted_at = updated_at + INTERVAL 3 HOUR AND @pode_registrar = 1;

INSERT INTO manutencao_biz02_datetime (tabela, coluna, linha_id, linha_chave, valor_utc)
SELECT 'portal_pa_map', 'deleted_at', id, '', deleted_at
FROM portal_pa_map
WHERE deleted_at = updated_at + INTERVAL 3 HOUR AND @pode_registrar = 1;

-- Chamados criticos: as mesmas colunas recebem hora digitada pelo usuario (ja
-- no fuso certo) e hora preenchida pelo MySQL na troca de status. So entra o
-- que tem a assinatura da troca de status: exatamente 3 h adiante do updated_at.
INSERT INTO manutencao_biz02_datetime (tabela, coluna, linha_id, linha_chave, valor_utc)
SELECT 'portal_critical_incidents', 'war_room_started_at', id, '', war_room_started_at
FROM portal_critical_incidents
WHERE war_room_started_at = updated_at + INTERVAL 3 HOUR AND @pode_registrar = 1;

INSERT INTO manutencao_biz02_datetime (tabela, coluna, linha_id, linha_chave, valor_utc)
SELECT 'portal_critical_incidents', 'room_opened_at', id, '', room_opened_at
FROM portal_critical_incidents
WHERE room_opened_at = updated_at + INTERVAL 3 HOUR AND @pode_registrar = 1;

INSERT INTO manutencao_biz02_datetime (tabela, coluna, linha_id, linha_chave, valor_utc)
SELECT 'portal_critical_incidents', 'mitigated_at', id, '', mitigated_at
FROM portal_critical_incidents
WHERE mitigated_at = updated_at + INTERVAL 3 HOUR AND @pode_registrar = 1;

INSERT INTO manutencao_biz02_datetime (tabela, coluna, linha_id, linha_chave, valor_utc)
SELECT 'portal_critical_incidents', 'resolved_at', id, '', resolved_at
FROM portal_critical_incidents
WHERE resolved_at = updated_at + INTERVAL 3 HOUR AND @pode_registrar = 1;

INSERT INTO manutencao_biz02_datetime (tabela, coluna, linha_id, linha_chave, valor_utc)
SELECT 'portal_critical_incidents', 'normalized_at', id, '', normalized_at
FROM portal_critical_incidents
WHERE normalized_at = updated_at + INTERVAL 3 HOUR AND @pode_registrar = 1;

INSERT INTO manutencao_marcadores (chave, registrado_em, detalhe)
SELECT 'biz02_corte',
       NOW(),
       CONCAT('utc=', UTC_TIMESTAMP(),
              '; fuso_servidor=', @fuso_servidor,
              '; linhas=', (SELECT COUNT(*) FROM manutencao_biz02_datetime),
              '; guardas_ignoradas=', @ignorar_guardas)
FROM DUAL
WHERE @pode_registrar = 1;

COMMIT;

SELECT CASE
           WHEN @pode_registrar = 1 THEN 'CORTE REGISTRADO'
           WHEN @ja_registrado > 0 THEN 'NADA FEITO: corte ja registrado antes'
           ELSE 'ABORTADO PELAS GUARDAS: nada foi fotografado'
       END AS resultado,
       @g1_formato_novo AS g1_linhas_formato_novo,
       @g2_salto_auditoria AS g2_saltos_auditoria,
       @g3_servidor_fora_de_utc AS g3_minutos_fora_de_utc,
       @fuso_servidor AS fuso_servidor;

SELECT chave, registrado_em, detalhe FROM manutencao_marcadores WHERE chave LIKE 'biz02%';

SELECT tabela, coluna, COUNT(*) AS linhas, MIN(valor_utc) AS mais_antiga, MAX(valor_utc) AS mais_recente
FROM manutencao_biz02_datetime
GROUP BY tabela, coluna
ORDER BY tabela, coluna;
