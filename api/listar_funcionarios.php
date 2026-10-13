<?php
/**
 * API: Listar funcionários ativos para preenchimento dos selects na tela principal
 * Retorna id, nome, equipe e status ativo de todos os funcionários
 */
require_once __DIR__ . '/../init.php';
header('Content-Type: application/json; charset=utf-8');

require_get_method();
require_portal_auth();

global $gerenciador;
$lista = [];
// [Lote 5b] Inativos, login AD e perfil para quem gerencia funcionários.
$is_admin = in_array('funcionarios.manage', portal_permissions_for_role(current_portal_role()), true);
// [P12, F1] "Fora do Mapa PA e da escala" para a gestao (telas de escala e
// cadastro). Sem a coluna (migration 011 ainda nao aplicada), o campo fica de
// fora e a lista continua.
$fora_mapa = null;
if (portal_role_is_manager(current_portal_role())) {
    try {
        $fora_mapa = [];
        foreach (get_db_connection()->query('SELECT id, fora_mapa_escala FROM funcionarios')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fora_mapa[(int)$row['id']] = (int)$row['fora_mapa_escala'] === 1;
        }
    } catch (Throwable $e) {
        error_log('[FUNCIONARIO] fora_mapa_escala indisponivel: ' . get_class($e));
        $fora_mapa = null;
    }
}

// [F4] Data do arquivamento (so a data, sem o autor) para quem gerencia
// funcionarios: a tela separa o arquivado do inativo antigo, sem data, que
// ainda pode ser arquivado (D5). Sem a coluna (migration 014 ainda nao
// aplicada), o campo fica de fora e a lista continua.
$arquivado_em = null;
if ($is_admin) {
    try {
        $arquivado_em = [];
        foreach (get_db_connection()->query('SELECT id, arquivado_em FROM funcionarios WHERE ativo = 0')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $arquivado_em[(int)$row['id']] = $row['arquivado_em'] === null ? null : substr((string)$row['arquivado_em'], 0, 10);
        }
    } catch (Throwable $e) {
        error_log('[FUNCIONARIO] arquivado_em indisponivel: ' . get_class($e));
        $arquivado_em = null;
    }
}

if ($gerenciador) {
    foreach ($gerenciador->getFuncionarios() as $f) {
        $ativo = $f->ativo ?? true;
        if (!$is_admin && !$ativo) {
            continue;
        }

        $item = [
            'id'     => $f->id,
            'nome'   => $f->nome,
            'equipe' => $f->equipe,
            'ativo'  => $ativo,
            'em_pausa' => $f->em_pausa ?? false,
            'jornada_entrada' => $f->jornada_entrada ?? '08:00',
            'jornada_saida' => $f->jornada_saida ?? '17:00',
            'almoco_inicio' => $f->almoco_inicio ?? '12:00',
            'almoco_fim' => $f->almoco_fim ?? '13:00',
            'disponibilidade' => $f->status_disponibilidade(),
        ];
        if (is_array($fora_mapa)) {
            $item['fora_mapa_escala'] = $fora_mapa[(int)$f->id] ?? false;
        }
        if (is_array($arquivado_em)) {
            $item['arquivado_em'] = $ativo ? null : ($arquivado_em[(int)$f->id] ?? null);
        }
        if ($is_admin) {
            $item['ad_login'] = $f->ad_login ?? null;
            $item['access_role'] = $f->access_role ?? 'tecnico';
        }
        $lista[] = $item;
    }
    // Ordenar por nome
    usort($lista, fn($a, $b) => strcmp($a['nome'], $b['nome']));
}

json_response(['sucesso' => true, 'funcionarios' => $lista]);
