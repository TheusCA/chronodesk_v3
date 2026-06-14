<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../init.php';

require_get_method();
require_portal_auth(['admin', 'gestor']);

global $gerenciador;
$metricas = $gerenciador->obter_metricas();
$timestamp = date('Ymd_His');
$request_id = $timestamp . '_' . bin2hex(random_bytes(8));

function csv_safe_value($value) {
    if (!is_string($value)) {
        return $value;
    }

    return preg_match('/^[=+\-@]/u', $value) ? "'" . $value : $value;
}

function csv_safe_row($handle, array $row): void {
    fputcsv($handle, array_map('csv_safe_value', $row));
}

// Criar arquivo temporário para métricas
$temp_dir = sys_get_temp_dir();
$relatorio_path = $temp_dir . '/relatorio_metricas_' . $request_id . '.csv';
$relatorio_detalhado_path = $temp_dir . '/relatorio_pausas_detalhado_' . $request_id . '.csv';
$relatorio_zip_path = $temp_dir . '/relatorio_completo_' . $request_id . '.zip';
$temporary_files = [$relatorio_path, $relatorio_detalhado_path, $relatorio_zip_path];
register_shutdown_function(static function () use ($temporary_files): void {
    foreach ($temporary_files as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
});

$file = @fopen($relatorio_path, 'wb');
if ($file === false) {
    audit_log('RELATORIO_DOWNLOAD_FAILURE', 'Falha ao criar arquivo temporario de metricas', 'WARNING');
    json_response(['sucesso' => false, 'mensagem' => 'Erro ao gerar relatório.'], 500);
}
csv_safe_row($file, ['tipo_metrica', 'categoria', 'valor', 'unidade']);

// Total de pausas por funcionário
foreach ($metricas['total_pausas_funcionario'] as $func => $total) {
    csv_safe_row($file, ['Total de Pausas', $func, $total, 'pausas']);
}

// Duração total por funcionário
foreach ($metricas['duracao_total_funcionario'] as $func => $duracao) {
    csv_safe_row($file, ['Duração Total', $func, $duracao, 'segundos']);
}

// Duração média por funcionário
foreach ($metricas['duracao_media_funcionario'] as $func => $media) {
    csv_safe_row($file, ['Duração Média', $func, round($media, 2), 'segundos']);
}

// Total de pausas por equipe
foreach ($metricas['total_pausas_equipe'] as $equipe => $total) {
    csv_safe_row($file, ['Total de Pausas', 'Equipe ' . strtoupper($equipe), $total, 'pausas']);
}

// Duração total por equipe
foreach ($metricas['duracao_total_equipe'] as $equipe => $duracao) {
    csv_safe_row($file, ['Duração Total', 'Equipe ' . strtoupper($equipe), $duracao, 'segundos']);
}

// Duração média por equipe
foreach ($metricas['duracao_media_equipe'] as $equipe => $media) {
    csv_safe_row($file, ['Duração Média', 'Equipe ' . strtoupper($equipe), round($media, 2), 'segundos']);
}

// Pausas excedidas
foreach ($metricas['pausas_excedidas'] as $func => $excedidas) {
    csv_safe_row($file, ['Pausas Excedidas', $func, $excedidas, 'pausas excedidas']);
}

// Total de pausas por motivo
foreach ($metricas['total_pausas_por_motivo'] as $motivo => $total) {
    csv_safe_row($file, ['Total de Pausas por Motivo', $motivo, $total, 'pausas']);
}

// Duração total por motivo
foreach ($metricas['duracao_total_por_motivo'] as $motivo => $duracao) {
    csv_safe_row($file, ['Duração Total por Motivo', $motivo, $duracao, 'segundos']);
}

// Duração média por motivo
foreach ($metricas['duracao_media_por_motivo'] as $motivo => $media) {
    csv_safe_row($file, ['Duração Média por Motivo', $motivo, round($media, 2), 'segundos']);
}

// Alertas 15min por funcionário
foreach ($metricas['alertas_15min_funcionario'] as $func => $alertas) {
    csv_safe_row($file, ['Alertas 15min', $func, $alertas, 'alertas']);
}

// Alertas 15min por equipe
foreach ($metricas['alertas_15min_equipe'] as $equipe => $alertas) {
    csv_safe_row($file, ['Alertas 15min', 'Equipe ' . strtoupper($equipe), $alertas, 'alertas']);
}

// Alertas 20min por funcionário
foreach ($metricas['alertas_20min_funcionario'] as $func => $alertas) {
    csv_safe_row($file, ['Alertas 20min', $func, $alertas, 'alertas']);
}

// Alertas 20min por equipe
foreach ($metricas['alertas_20min_equipe'] as $equipe => $alertas) {
    csv_safe_row($file, ['Alertas 20min', 'Equipe ' . strtoupper($equipe), $alertas, 'alertas']);
}

// Pausas de Reunião Aprovadas
foreach ($metricas['pausas_reuniao_aprovadas'] as $func => $total) {
    csv_safe_row($file, ['Pausas de Reunião Aprovadas', $func, $total, 'pausas']);
}

// Pausas de Reunião Rejeitadas
foreach ($metricas['pausas_reuniao_rejeitadas'] as $func => $total) {
    csv_safe_row($file, ['Pausas de Reunião Rejeitadas', $func, $total, 'pausas']);
}

// Pausas de Reunião Pendentes
foreach ($metricas['pausas_reuniao_pendentes'] as $func => $total) {
    csv_safe_row($file, ['Pausas de Reunião Pendentes', $func, $total, 'pausas']);
}

fclose($file);

// Criar arquivo detalhado
$file_detalhado = @fopen($relatorio_detalhado_path, 'wb');
if ($file_detalhado === false) {
    audit_log('RELATORIO_DOWNLOAD_FAILURE', 'Falha ao criar arquivo temporario detalhado', 'WARNING');
    json_response(['sucesso' => false, 'mensagem' => 'Erro ao gerar relatório.'], 500);
}
$headers_detalhado = [
    'id_funcionario', 'nome_funcionario', 'equipe', 'inicio_pausa', 'fim_pausa',
    'duracao_segundos', 'duracao_formatada', 'motivo_pausa', 'alerta_15min',
    'alerta_20min', 'excedeu_limite', 'status_aprovacao', 'observacao_reuniao'
];
csv_safe_row($file_detalhado, $headers_detalhado);

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

    csv_safe_row($file_detalhado, [
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
if (!class_exists('ZipArchive')) {
    audit_log('RELATORIO_DOWNLOAD_FAILURE', 'Extensao ZipArchive indisponivel', 'WARNING');
    json_response(['sucesso' => false, 'mensagem' => 'Exportação indisponível no servidor.'], 503);
}
$zip = new ZipArchive();
if ($zip->open($relatorio_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    $zip->addFile($relatorio_path, 'relatorio_metricas_' . $timestamp . '.csv');
    $zip->addFile($relatorio_detalhado_path, 'relatorio_pausas_detalhado_' . $timestamp . '.csv');
    $zip->close();
} else {
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
exit;
?>

