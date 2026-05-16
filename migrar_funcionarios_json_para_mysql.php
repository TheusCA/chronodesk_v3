<?php
/**
 * Migra funcionarios.json para a tabela funcionarios.
 *
 * Uso:
 *   php migrar_funcionarios_json_para_mysql.php
 *
 * O script é idempotente: insere/atualiza registros pelo ID e não apaga dados.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Execute este script via CLI.\n";
    exit(1);
}

function coluna_existe(PDO $pdo, $tabela, $coluna) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :tabela
           AND COLUMN_NAME = :coluna"
    );
    $stmt->execute([':tabela' => $tabela, ':coluna' => $coluna]);
    return (int)$stmt->fetchColumn() > 0;
}

function indice_existe(PDO $pdo, $tabela, $indice) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :tabela
           AND INDEX_NAME = :indice"
    );
    $stmt->execute([':tabela' => $tabela, ':indice' => $indice]);
    return (int)$stmt->fetchColumn() > 0;
}

$pdo = get_db_connection();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS funcionarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(100) NOT NULL,
        equipe VARCHAR(10) NOT NULL DEFAULT 'n1',
        ad_login VARCHAR(100) DEFAULT NULL COMMENT 'Login do Active Directory',
        jornada_entrada TIME NOT NULL DEFAULT '08:00:00',
        jornada_saida TIME NOT NULL DEFAULT '17:00:00',
        almoco_inicio TIME NOT NULL DEFAULT '12:00:00',
        almoco_fim TIME NOT NULL DEFAULT '13:00:00',
        ativo BOOLEAN NOT NULL DEFAULT TRUE,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_funcionarios_equipe (equipe),
        INDEX idx_funcionarios_ad_login (ad_login),
        INDEX idx_funcionarios_ativo (ativo)
    ) ENGINE=InnoDB"
);

$alteracoes = [
    'ad_login' => "ALTER TABLE funcionarios ADD COLUMN ad_login VARCHAR(100) DEFAULT NULL COMMENT 'Login do Active Directory' AFTER equipe",
    'jornada_entrada' => "ALTER TABLE funcionarios ADD COLUMN jornada_entrada TIME NOT NULL DEFAULT '08:00:00'",
    'jornada_saida' => "ALTER TABLE funcionarios ADD COLUMN jornada_saida TIME NOT NULL DEFAULT '17:00:00'",
    'almoco_inicio' => "ALTER TABLE funcionarios ADD COLUMN almoco_inicio TIME NOT NULL DEFAULT '12:00:00'",
    'almoco_fim' => "ALTER TABLE funcionarios ADD COLUMN almoco_fim TIME NOT NULL DEFAULT '13:00:00'",
    'ativo' => "ALTER TABLE funcionarios ADD COLUMN ativo BOOLEAN NOT NULL DEFAULT TRUE",
    'criado_em' => "ALTER TABLE funcionarios ADD COLUMN criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    'atualizado_em' => "ALTER TABLE funcionarios ADD COLUMN atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
];

foreach ($alteracoes as $coluna => $sql) {
    if (!coluna_existe($pdo, 'funcionarios', $coluna)) {
        $pdo->exec($sql);
        echo "Coluna adicionada: {$coluna}\n";
    }
}

if (!coluna_existe($pdo, 'funcionarios', 'ad_login_ativo')) {
    try {
        $pdo->exec(
            "ALTER TABLE funcionarios
             ADD COLUMN ad_login_ativo VARCHAR(100) GENERATED ALWAYS AS (
                 CASE
                     WHEN ativo = 1 AND ad_login IS NOT NULL AND ad_login <> '' THEN ad_login
                     ELSE NULL
                 END
             ) STORED"
        );
        echo "Coluna gerada adicionada: ad_login_ativo\n";
    } catch (Exception $e) {
        echo "Aviso: não foi possível criar ad_login_ativo automaticamente: {$e->getMessage()}\n";
    }
}

if (coluna_existe($pdo, 'funcionarios', 'ad_login_ativo') && !indice_existe($pdo, 'funcionarios', 'uq_funcionarios_ad_login_ativo')) {
    try {
        $pdo->exec("ALTER TABLE funcionarios ADD UNIQUE KEY uq_funcionarios_ad_login_ativo (ad_login_ativo)");
        echo "Índice único criado: uq_funcionarios_ad_login_ativo\n";
    } catch (Exception $e) {
        echo "Aviso: revise logins AD duplicados antes de criar índice único: {$e->getMessage()}\n";
    }
}

$funcionarios = carregar_funcionarios_json_fallback();
if (count($funcionarios) === 0) {
    echo "Nenhum funcionário encontrado em funcionarios.json.\n";
    exit(0);
}

$stmt = $pdo->prepare(
    "INSERT INTO funcionarios (
        id, nome, equipe, ad_login, jornada_entrada, jornada_saida,
        almoco_inicio, almoco_fim, ativo
    ) VALUES (
        :id, :nome, :equipe, :ad_login, :jornada_entrada, :jornada_saida,
        :almoco_inicio, :almoco_fim, :ativo
    )
    ON DUPLICATE KEY UPDATE
        nome = VALUES(nome),
        equipe = VALUES(equipe),
        ad_login = VALUES(ad_login),
        jornada_entrada = VALUES(jornada_entrada),
        jornada_saida = VALUES(jornada_saida),
        almoco_inicio = VALUES(almoco_inicio),
        almoco_fim = VALUES(almoco_fim),
        ativo = VALUES(ativo)"
);

$migrados = 0;
foreach ($funcionarios as $func) {
    try {
        $stmt->execute([
            ':id' => $func['id'],
            ':nome' => $func['nome'],
            ':equipe' => $func['equipe'],
            ':ad_login' => $func['ad_login'],
            ':jornada_entrada' => formatar_hora_mysql($func['jornada_entrada'], '08:00:00'),
            ':jornada_saida' => formatar_hora_mysql($func['jornada_saida'], '17:00:00'),
            ':almoco_inicio' => formatar_hora_mysql($func['almoco_inicio'], '12:00:00'),
            ':almoco_fim' => formatar_hora_mysql($func['almoco_fim'], '13:00:00'),
            ':ativo' => $func['ativo'] ? 1 : 0,
        ]);
        $migrados++;
    } catch (Exception $e) {
        echo "Aviso: funcionário ID {$func['id']} não migrado: {$e->getMessage()}\n";
    }
}

echo "Migração concluída. Funcionários processados: {$migrados}\n";
