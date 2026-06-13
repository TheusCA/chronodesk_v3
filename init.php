<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes/Funcionario.php';
require_once __DIR__ . '/classes/GerenciadorPausas.php';

// Inicializar CSV
inicializar_csv();

// Limpar estado antigo se necessário
limpar_estado_antigo();

// Carregar configurações do sistema
$config = carregar_configuracao();
$limite_pausa = $config['limite_pausa_por_equipe'] ?? 2;
$duracao_pausa = $config['duracao_pausa_minutos'] ?? 20;

// Criar instância do gerenciador (global) com configurações carregadas
global $gerenciador;
$gerenciador = new GerenciadorPausas($limite_pausa, $duracao_pausa);

// Carregar funcionários do MySQL; funcionarios.json é apenas fallback temporário
$funcionarios = carregar_funcionarios_sistema();
foreach ($funcionarios as $func_data) {
    $jornada_entrada = $func_data['jornada_entrada'] ?? '08:00';
    $jornada_saida = $func_data['jornada_saida'] ?? '17:00';
    $almoco_inicio = $func_data['almoco_inicio'] ?? '12:00';
    $almoco_fim = $func_data['almoco_fim'] ?? '13:00';
    $ativo = isset($func_data['ativo']) ? (bool)$func_data['ativo'] : true;
    $ad_login = $func_data['ad_login'] ?? null;
    $access_role = $func_data['access_role'] ?? 'tecnico';
    
    $gerenciador->adicionar_funcionario(new Funcionario(
        $func_data['id'],
        $func_data['nome'],
        $func_data['equipe'],
        $jornada_entrada,
        $jornada_saida,
        $almoco_inicio,
        $almoco_fim,
        $ativo,
        $ad_login,
        $access_role
    ));
}

// Carregar estado após adicionar todos os funcionários
$gerenciador->carregar_estado();


