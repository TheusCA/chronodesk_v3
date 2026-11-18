<?php
// [9.17.7] Importar do Controle de CHG v5.2 (so admin: chg.import; CSRF).
// POST {action: preview|confirm, dados?: <JSON v2>, catalogo?: <JSON do
// catalogo>}, ate 2 MB. A previa devolve so contagens; a confirmacao refaz a
// previa e grava, numa transacao so. Auditoria so com as contagens.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../services/ChgImport.php';

if (!chg_module_enabled()) {
    json_response(['sucesso' => false, 'mensagem' => 'Recurso não encontrado.'], 404);
}

require_http_method(['POST']);
$role = require_portal_auth();

try {
    if (!ChgAccess::canImport($role)) {
        audit_log('CHG_NEGADO', "Perfil {$role} sem permissao tentou importar no Controle de CHG.", 'WARNING');
        json_response(['sucesso' => false, 'mensagem' => 'Somente administradores importam do Controle v5.'], 403);
    }
    $data = portal_json_input(ChgImport::MAX_BYTES);
    $action = $data['action'] ?? null;
    if (!in_array($action, ['preview', 'confirm'], true) || array_diff(array_keys($data), ['action', 'dados', 'catalogo']) !== []) {
        throw new InvalidArgumentException('Importação inválida.');
    }
    $dados = $data['dados'] ?? null;
    $catalogo = $data['catalogo'] ?? null;
    if (($dados !== null && !is_array($dados)) || ($catalogo !== null && !is_array($catalogo))) {
        throw new InvalidArgumentException('Arquivo inválido: envie o JSON do Controle v5.');
    }
    $pdo = get_db_connection();
    $import = new ChgImport(new PdoChgStore($pdo), new DateTimeImmutable('now'));
    if ($action === 'preview') {
        json_response(['sucesso' => true] + $import->run($dados, $catalogo, false, portal_username()));
    }
    $pdo->beginTransaction();
    try {
        $result = $import->run($dados, $catalogo, true, portal_username());
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $c = $result['contagens'];
    audit_log('CHG_IMPORTADO', 'Importacao do Controle v5: novos ' . $c['novos'] . ', atualizados ' . $c['atualizados'] . ', carteiras novas ' . ($c['carteiras_novas'] ?? 0) . ', locais novos ' . ($c['locais_novos'] ?? 0), 'WARNING');
    json_response(['sucesso' => true, 'mensagem' => 'Importação concluída.'] + $result);
} catch (Throwable $e) {
    portal_operational_error($e);
}
