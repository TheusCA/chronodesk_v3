<?php
/**
 * API: Listar funcionários ativos para preenchimento dos selects na tela principal
 * Retorna id, nome, equipe e status ativo de todos os funcionários
 */
require_once __DIR__ . '/../init.php';
header('Content-Type: application/json; charset=utf-8');

global $gerenciador;
$lista = [];

if ($gerenciador) {
    foreach ($gerenciador->getFuncionarios() as $f) {
        $lista[] = [
            'id'     => $f->id,
            'nome'   => $f->nome,
            'equipe' => $f->equipe,
            'ativo'  => $f->ativo ?? true,
        ];
    }
    // Ordenar por nome
    usort($lista, fn($a, $b) => strcmp($a['nome'], $b['nome']));
}

json_response(['sucesso' => true, 'funcionarios' => $lista]);
