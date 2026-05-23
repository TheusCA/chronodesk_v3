<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Execute este script via CLI.\n";
    exit(1);
}

echo "Iniciando migração de CSV para MySQL...\n";

if (!file_exists(PAUSAS_CSV)) {
    die("Arquivo CSV não encontrado: " . PAUSAS_CSV . "\n");
}

try {
    $pdo = get_db_connection();
} catch (Exception $e) {
    die("Erro ao conectar no banco: " . $e->getMessage() . "\nVerifique as configurações em config.php\n");
}

$file = fopen(PAUSAS_CSV, 'r');
if (!$file) {
    die("Erro ao abrir arquivo CSV.\n");
}

// Ler cabeçalho
$headers = fgetcsv($file);
if (!$headers) {
    die("Arquivo CSV vazio ou inválido.\n");
}

echo "Colunas encontradas no CSV: " . implode(", ", $headers) . "\n";

$count = 0;
$errors = 0;

$pdo->beginTransaction();

try {
    $sql = "INSERT INTO pausas (
        id_funcionario, nome_funcionario, equipe, inicio_pausa, fim_pausa, 
        duracao_segundos, motivo_pausa, alerta_15min, alerta_20min, 
        status_aprovacao, observacao_reuniao
    ) VALUES (
        :id_funcionario, :nome_funcionario, :equipe, :inicio_pausa, :fim_pausa, 
        :duracao_segundos, :motivo_pausa, :alerta_15min, :alerta_20min, 
        :status_aprovacao, :observacao_reuniao
    )";
    
    $stmt = $pdo->prepare($sql);

    while (($row = fgetcsv($file)) !== false) {
        // Combinar headers com valores
        if (count($headers) !== count($row)) {
            echo "Aviso: Linha com número de colunas incorreto. Pulando.\n";
            $errors++;
            continue;
        }
        
        $data = array_combine($headers, $row);
        
        // Converter valores booleanos e datas
        $alerta_15min = ($data['alerta_15min'] === 'True' || $data['alerta_15min'] == 1) ? 1 : 0;
        $alerta_20min = ($data['alerta_20min'] === 'True' || $data['alerta_20min'] == 1) ? 1 : 0;
        
        // Converter datas para formato MySQL (Y-m-d H:i:s)
        try {
            $inicio = new DateTime($data['inicio_pausa']);
            $inicio_fmt = $inicio->format('Y-m-d H:i:s');
            
            $fim = new DateTime($data['fim_pausa']);
            $fim_fmt = $fim->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            echo "Erro ao formatar data na linha " . ($count + 2) . ": " . $e->getMessage() . "\n";
            $errors++;
            continue;
        }

        $stmt->execute([
            ':id_funcionario' => $data['id_funcionario'],
            ':nome_funcionario' => $data['nome_funcionario'],
            ':equipe' => $data['equipe'],
            ':inicio_pausa' => $inicio_fmt,
            ':fim_pausa' => $fim_fmt,
            ':duracao_segundos' => $data['duracao_segundos'],
            ':motivo_pausa' => $data['motivo_pausa'],
            ':alerta_15min' => $alerta_15min,
            ':alerta_20min' => $alerta_20min,
            ':status_aprovacao' => $data['status_aprovacao'] ?? null,
            ':observacao_reuniao' => $data['observacao_reuniao'] ?? null
        ]);
        
        $count++;
    }

    $pdo->commit();
    echo "Migração concluída com sucesso!\n";
    echo "Total de registros importados: $count\n";
    if ($errors > 0) echo "Erros encontrados: $errors\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "Erro fatal durante a migração: " . $e->getMessage() . "\n";
}

fclose($file);
?>
