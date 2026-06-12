<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../init.php';

require_get_method();
verificar_login_api();

global $gerenciador;
$metricas = $gerenciador->obter_metricas();
$timestamp = date('Ymd_His');

// Criar arquivo temporário para métricas
$temp_dir = sys_get_temp_dir();
$relatorio_path = $temp_dir . '/relatorio_metricas_' . $timestamp . '.csv';

$file = fopen($relatorio_path, 'w');
fputcsv($file, ['tipo_metrica', 'categoria', 'valor', 'unidade']);

// Total de pausas por funcionário
foreach ($metricas['total_pausas_funcionario'] as $func => $total) {
    fputcsv($file, ['Total de Pausas', $func, $total, 'pausas']);
}

// Duração total por funcionário
foreach ($metricas['duracao_total_funcionario'] as $func => $duracao) {
    fputcsv($file, ['Duração Total', $func, $duracao, 'segundos']);
}

// Duração média por funcionário
foreach ($metricas['duracao_media_funcionario'] as $func => $media) {
    fputcsv($file, ['Duração Média', $func, round($media, 2), 'segundos']);
}

// Total de pausas por equipe
foreach ($metricas['total_pausas_equipe'] as $equipe => $total) {
    fputcsv($file, ['Total de Pausas', 'Equipe ' . strtoupper($equipe), $total, 'pausas']);
}

// Duração total por equipe
foreach ($metricas['duracao_total_equipe'] as $equipe => $duracao) {
    fputcsv($file, ['Duração Total', 'Equipe ' . strtoupper($equipe), $duracao, 'segundos']);
}

// Duração média por equipe
foreach ($metricas['duracao_media_equipe'] as $equipe => $media) {
    fputcsv($file, ['Duração Média', 'Equipe ' . strtoupper($equipe), round($media, 2), 'segundos']);
}

// Pausas excedidas
foreach ($metricas['pausas_excedidas'] as $func => $excedidas) {
    fputcsv($file, ['Pausas Excedidas', $func, $excedidas, 'pausas excedidas']);
}

// Total de pausas por motivo
foreach ($metricas['total_pausas_por_motivo'] as $motivo => $total) {
    fputcsv($file, ['Total de Pausas por Motivo', $motivo, $total, 'pausas']);
}

// Duração total por motivo
foreach ($metricas['duracao_total_por_motivo'] as $motivo => $duracao) {
    fputcsv($file, ['Duração Total por Motivo', $motivo, $duracao, 'segundos']);
}

// Duração média por motivo
foreach ($metricas['duracao_media_por_motivo'] as $motivo => $media) {
    fputcsv($file, ['Duração Média por Motivo', $motivo, round($media, 2), 'segundos']);
}

// Alertas 15min por funcionário
foreach ($metricas['alertas_15min_funcionario'] as $func => $alertas) {
    fputcsv($file, ['Alertas 15min', $func, $alertas, 'alertas']);
}

// Alertas 15min por equipe
foreach ($metricas['alertas_15min_equipe'] as $equipe => $alertas) {
    fputcsv($file, ['Alertas 15min', 'Equipe ' . strtoupper($equipe), $alertas, 'alertas']);
}

// Alertas 20min por funcionário
foreach ($metricas['alertas_20min_funcionario'] as $func => $alertas) {
    fputcsv($file, ['Alertas 20min', $func, $alertas, 'alertas']);
}

// Alertas 20min por equipe
foreach ($metricas['alertas_20min_equipe'] as $equipe => $alertas) {
    fputcsv($file, ['Alertas 20min', 'Equipe ' . strtoupper($equipe), $alertas, 'alertas']);
}

// Pausas de Reunião Aprovadas
foreach ($metricas['pausas_reuniao_aprovadas'] as $func => $total) {
    fputcsv($file, ['Pausas de Reunião Aprovadas', $func, $total, 'pausas']);
}

// Pausas de Reunião Rejeitadas
foreach ($metricas['pausas_reuniao_rejeitadas'] as $func => $total) {
    fputcsv($file, ['Pausas de Reunião Rejeitadas', $func, $total, 'pausas']);
}

// Pausas de Reunião Pendentes
foreach ($metricas['pausas_reuniao_pendentes'] as $func => $total) {
    fputcsv($file, ['Pausas de Reunião Pendentes', $func, $total, 'pausas']);
}

fclose($file);

// Criar arquivo detalhado
$relatorio_detalhado_path = $temp_dir . '/relatorio_pausas_detalhado_' . $timestamp . '.csv';
$file_detalhado = fopen($relatorio_detalhado_path, 'w');
$headers_detalhado = [
    'id_funcionario', 'nome_funcionario', 'equipe', 'inicio_pausa', 'fim_pausa',
    'duracao_segundos', 'duracao_formatada', 'motivo_pausa', 'alerta_15min',
    'alerta_20min', 'excedeu_limite', 'status_aprovacao', 'observacao_reuniao'
];
fputcsv($file_detalhado, $headers_detalhado);

foreach ($metricas['pausas_detalhadas'] as $pausa) {
    $duracao_seg = $pausa['duracao_segundos'];
    $horas = floor($duracao_seg / 3600);
    $minutos = floor(($duracao_seg % 3600) / 60);
    $segundos = $duracao_seg % 60;

    if ($horas > 0) {
        $duracao_formatada = "{$horas}h {$minutos}m {$segundos}s";
    } elseif ($minutos > 0) {
        $duracao_formatada = "{$minutos}m {$segundos}s";
    } else {
        $duracao_formatada = "{$segundos}s";
    }

    // Formatar datas
    $inicio_formatado = $pausa['inicio_pausa'];
    $fim_formatado = $pausa['fim_pausa'];
    
    try {
        if ($inicio_formatado) {
            $inicio_dt = new DateTime($inicio_formatado);
            $inicio_formatado = $inicio_dt->format('d/m/Y H:i:s');
        }
    } catch (Exception $e) {
        // Manter formato original em caso de erro
    }
    
    try {
        if ($fim_formatado) {
            $fim_dt = new DateTime($fim_formatado);
            $fim_formatado = $fim_dt->format('d/m/Y H:i:s');
        }
    } catch (Exception $e) {
        // Manter formato original em caso de erro
    }

    fputcsv($file_detalhado, [
        $pausa['id_funcionario'],
        $pausa['nome_funcionario'],
        strtoupper($pausa['equipe']),
        $inicio_formatado,
        $fim_formatado,
        $pausa['duracao_segundos'],
        $duracao_formatada,
        $pausa['motivo_pausa'],
        $pausa['alerta_15min'] ? 'Sim' : 'Não',
        $pausa['alerta_20min'] ? 'Sim' : 'Não',
        $pausa['excedeu_limite'] ? 'Sim' : 'Não',
        $pausa['status_aprovacao'],
        $pausa['observacao_reuniao'] ?: 'N/A'
    ]);
}
fclose($file_detalhado);

// Criar ZIP
$relatorio_zip_path = $temp_dir . '/relatorio_completo_' . $timestamp . '.zip';
$zip = new ZipArchive();
if ($zip->open($relatorio_zip_path, ZipArchive::CREATE) === TRUE) {
    $zip->addFile($relatorio_path, 'relatorio_metricas_' . $timestamp . '.csv');
    $zip->addFile($relatorio_detalhado_path, 'relatorio_pausas_detalhado_' . $timestamp . '.csv');
    $zip->close();
} else {
    @unlink($relatorio_path);
    @unlink($relatorio_detalhado_path);
    audit_log('RELATORIO_DOWNLOAD_FAILURE', 'Falha ao criar ZIP de relatório', 'WARNING');
    json_response(['sucesso' => false, 'mensagem' => 'Erro ao gerar relatório.'], 500);
}

// Enviar arquivo ZIP
audit_log('RELATORIO_DOWNLOAD', 'Download de relatório completo solicitado', 'INFO');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="relatorio_completo_' . $timestamp . '.zip"');
header('Content-Length: ' . filesize($relatorio_zip_path));
readfile($relatorio_zip_path);

// Limpar arquivos temporários
@unlink($relatorio_path);
@unlink($relatorio_detalhado_path);
@unlink($relatorio_zip_path);
exit;
?>

