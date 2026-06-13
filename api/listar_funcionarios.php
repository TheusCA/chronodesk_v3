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
$is_admin = current_portal_role() === 'admin';

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
