<?php
require_once __DIR__ . '/Funcionario.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

class GerenciadorPausas {
    private $funcionarios;
    public $limite_pausa_por_equipe;
    private $duracao_pausa_minutos;
    private $pdo;

    public function __construct($limite_pausa_por_equipe = 2, $duracao_pausa_minutos = 20) {
        $this->funcionarios = [];
        $this->limite_pausa_por_equipe = $limite_pausa_por_equipe;
        $this->duracao_pausa_minutos = $duracao_pausa_minutos;
        
        try {
            $this->pdo = get_db_connection();
        } catch (Exception $e) {
            // Em caso de erro de conexão, logar e continuar (pode afetar funcionalidades que dependem do BD)
            error_log("GerenciadorPausas: " . $e->getMessage());
            $this->pdo = null;
        }
    }

    public function carregar_estado() {
        if (file_exists(ESTADO_JSON)) {
            $estado = json_decode(file_get_contents(ESTADO_JSON), true);
            if ($estado && isset($estado['funcionarios'])) {
                foreach ($estado['funcionarios'] as $id => $data) {
                    if (isset($this->funcionarios[$id])) {
                        $func = $this->funcionarios[$id];
                        $func->em_pausa = $data['em_pausa'] ?? false;
                        $func->inicio_pausa = ($data['inicio_pausa'] ?? null)
                            ? new DateTime($data['inicio_pausa'])
                            : null;
                        $func->motivo_pausa = $data['motivo_pausa'] ?? null;
                        $func->status_aprovacao = $data['status_aprovacao'] ?? null;
                        $func->solicitacao_timestamp = ($data['solicitacao_timestamp'] ?? null)
                            ? new DateTime($data['solicitacao_timestamp'])
                            : null;
                        $func->observacao_reuniao = $data['observacao_reuniao'] ?? null;
                    }
                }
            }
        }
    }

    public function adicionar_funcionario($funcionario) {
        $this->funcionarios[$funcionario->id] = $funcionario;
    }

    public function iniciar_pausa($funcionario_id, $motivo) {
        if (!isset($this->funcionarios[$funcionario_id])) {
            return ["sucesso" => false, "mensagem" => "Funcionário não encontrado."];
        }

        $funcionario = $this->funcionarios[$funcionario_id];

        // Verificar se funcionário está ativo
        if (!$funcionario->ativo) {
            return ["sucesso" => false, "mensagem" => "{$funcionario->nome} está inativo e não pode iniciar pausas."];
        }

        if ($funcionario->em_pausa) {
            return ["sucesso" => false, "mensagem" => "{$funcionario->nome} já está em pausa."];
        }

        if ($motivo === "Reunião") {
            return ["sucesso" => false, "mensagem" => "Pausas de reunião devem ser solicitadas para aprovação."];
        }

        // Verificar disponibilidade baseada em horários (jornada e almoço)
        if (!$funcionario->esta_disponivel()) {
            $status_disp = $funcionario->status_disponibilidade();
            return ["sucesso" => false, "mensagem" => "{$funcionario->nome} não está disponível no momento. Status: {$status_disp['label']}. Jornada: {$funcionario->jornada_entrada} - {$funcionario->jornada_saida}. Almoço: {$funcionario->almoco_inicio} - {$funcionario->almoco_fim}."];
        }

        $pausas_ativas_equipe = 0;
        foreach ($this->funcionarios as $f) {
            if ($f->equipe == $funcionario->equipe && $f->em_pausa) {
                if ($f->status_aprovacao == "aprovado" || $f->motivo_pausa != "Reunião") {
                    $pausas_ativas_equipe++;
                }
            }
        }

        if ($pausas_ativas_equipe >= $this->limite_pausa_por_equipe) {
            $equipe_nome = strtoupper($funcionario->equipe);
            return ["sucesso" => false, "mensagem" => "Limite de pausas simultâneas atingido para a equipe {$equipe_nome}. Atualmente há {$pausas_ativas_equipe} pausas ativas. Máximo permitido: {$this->limite_pausa_por_equipe} pausas."];
        }

        $funcionario->em_pausa = true;
        $funcionario->inicio_pausa = new DateTime();
        $funcionario->motivo_pausa = $motivo;

        $funcionario->status_aprovacao = "aprovado";

        $hora = $funcionario->inicio_pausa->format('H:i:s');
        $this->salvar_estado();
        return ["sucesso" => true, "mensagem" => "{$funcionario->nome} iniciou a pausa ({$motivo}) às {$hora}."];
    }

    public function solicitar_pausa($funcionario_id, $motivo, $observacao = '') {
        if (!isset($this->funcionarios[$funcionario_id])) {
            return ["sucesso" => false, "mensagem" => "Funcionário não encontrado."];
        }

        $funcionario = $this->funcionarios[$funcionario_id];
        if (!$funcionario->ativo) {
            return ["sucesso" => false, "mensagem" => "{$funcionario->nome} está inativo."];
        }
        if ($motivo !== "Reunião") {
            return ["sucesso" => false, "mensagem" => "Somente pausas de reunião exigem aprovação."];
        }
        if ($funcionario->em_pausa || $funcionario->status_aprovacao === 'pendente') {
            return ["sucesso" => false, "mensagem" => "{$funcionario->nome} já possui uma pausa ativa ou solicitação pendente."];
        }
        if (!$funcionario->esta_disponivel()) {
            return ["sucesso" => false, "mensagem" => "{$funcionario->nome} não está disponível no momento."];
        }

        $funcionario->em_pausa = false;
        $funcionario->inicio_pausa = null;
        $funcionario->motivo_pausa = $motivo;
        $funcionario->status_aprovacao = 'pendente';
        $funcionario->solicitacao_timestamp = new DateTime();
        $funcionario->observacao_reuniao = $observacao;
        $this->salvar_estado();

        return ["sucesso" => true, "mensagem" => "Solicitação de pausa enviada para aprovação."];
    }

    public function aprovar_pausa($funcionario_id) {
        $funcionario = $this->funcionarios[$funcionario_id] ?? null;
        if (!$funcionario) {
            return ["sucesso" => false, "mensagem" => "Funcionário não encontrado."];
        }
        if ($funcionario->status_aprovacao !== 'pendente') {
            return ["sucesso" => false, "mensagem" => "Não há solicitação pendente para este funcionário."];
        }

        $pausas_ativas = 0;
        foreach ($this->funcionarios as $outro) {
            if (
                $outro->equipe === $funcionario->equipe &&
                $outro->em_pausa &&
                $outro->status_aprovacao === 'aprovado'
            ) {
                $pausas_ativas++;
            }
        }
        if ($pausas_ativas >= $this->limite_pausa_por_equipe) {
            return [
                "sucesso" => false,
                "mensagem" => "Limite de pausas simultâneas atingido para a equipe " . strtoupper($funcionario->equipe) . "."
            ];
        }

        $funcionario->em_pausa = true;
        $funcionario->inicio_pausa = new DateTime();
        $funcionario->status_aprovacao = 'aprovado';
        $funcionario->solicitacao_timestamp = null;
        $this->salvar_estado();
        return ["sucesso" => true, "mensagem" => "Pausa aprovada para {$funcionario->nome}."];
    }

    public function rejeitar_pausa($funcionario_id) {
        $funcionario = $this->funcionarios[$funcionario_id] ?? null;
        if (!$funcionario) {
            return ["sucesso" => false, "mensagem" => "Funcionário não encontrado."];
        }
        if ($funcionario->status_aprovacao !== 'pendente') {
            return ["sucesso" => false, "mensagem" => "Não há solicitação pendente para este funcionário."];
        }

        $funcionario->em_pausa = false;
        $funcionario->inicio_pausa = null;
        $funcionario->motivo_pausa = null;
        $funcionario->status_aprovacao = null;
        $funcionario->solicitacao_timestamp = null;
        $funcionario->observacao_reuniao = null;
        $this->salvar_estado();
        return ["sucesso" => true, "mensagem" => "Solicitação rejeitada para {$funcionario->nome}."];
    }

    public function finalizar_pausa($funcionario_id) {
        if (!isset($this->funcionarios[$funcionario_id])) {
            return ["sucesso" => false, "mensagem" => "Funcionário não encontrado."];
        }

        $funcionario = $this->funcionarios[$funcionario_id];

        if (!$funcionario->em_pausa) {
            return ["sucesso" => false, "mensagem" => "{$funcionario->nome} não está em pausa."];
        }

        if ($funcionario->status_aprovacao !== 'aprovado' || !$funcionario->inicio_pausa) {
            return ["sucesso" => false, "mensagem" => "A pausa ainda não foi aprovada."];
        }

        $fim_pausa = new DateTime();
        $duracao_real = $fim_pausa->getTimestamp() - $funcionario->inicio_pausa->getTimestamp();

        // Verificar se a pausa atingiu os limites de alerta
        $LIMITE_ALERTA_15MIN = 15 * 60;
        $LIMITE_ALERTA_20MIN = 20 * 60;

        $alerta_15min = $duracao_real >= $LIMITE_ALERTA_15MIN;
        $alerta_20min = $duracao_real >= $LIMITE_ALERTA_20MIN;

        // Registrar a pausa no Banco de Dados (MySQL)
        if ($this->pdo) {
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
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    ':id_funcionario' => $funcionario->id,
                    ':nome_funcionario' => $funcionario->nome,
                    ':equipe' => $funcionario->equipe,
                    ':inicio_pausa' => $funcionario->inicio_pausa->format('Y-m-d H:i:s'),
                    ':fim_pausa' => $fim_pausa->format('Y-m-d H:i:s'),
                    ':duracao_segundos' => $duracao_real,
                    ':motivo_pausa' => $funcionario->motivo_pausa,
                    ':alerta_15min' => $alerta_15min ? 1 : 0,
                    ':alerta_20min' => $alerta_20min ? 1 : 0,
                    ':status_aprovacao' => $funcionario->status_aprovacao,
                    ':observacao_reuniao' => $funcionario->observacao_reuniao ?? ''
                ]);
            } catch (PDOException $e) {
                // Fallback para CSV em caso de erro no banco (segurança)
                error_log("Erro ao salvar pausa no MySQL: " . $e->getMessage());
                $this->salvar_csv_backup($funcionario, $fim_pausa, $duracao_real, $alerta_15min, $alerta_20min);
            }
        } else {
            // Se não houver conexão, salvar no CSV
            $this->salvar_csv_backup($funcionario, $fim_pausa, $duracao_real, $alerta_15min, $alerta_20min);
        }

        // Limpar dados da pausa
        $funcionario->em_pausa = false;
        $funcionario->inicio_pausa = null;
        $funcionario->motivo_pausa = null;
        $funcionario->status_aprovacao = null;
        $funcionario->solicitacao_timestamp = null;
        $funcionario->observacao_reuniao = null;

        $this->salvar_estado();

        $hora = $fim_pausa->format('H:i:s');
        $mensagem = "{$funcionario->nome} finalizou a pausa às {$hora}. Duração: {$duracao_real} segundos.";

        if ($duracao_real > $this->duracao_pausa_minutos * 60) {
            $mensagem .= " (Excedeu o tempo limite de {$this->duracao_pausa_minutos} minutos!)";
        }

        if ($alerta_20min) {
            $mensagem .= " 🚨 ALERTA CRÍTICO - Pausa excedeu 20 minutos!";
        } elseif ($alerta_15min) {
            $mensagem .= " ⚠️ ATENÇÃO - Pausa excedeu 15 minutos!";
        }

        return ["sucesso" => true, "mensagem" => $mensagem];
    }

    private function salvar_csv_backup($funcionario, $fim_pausa, $duracao_real, $alerta_15min, $alerta_20min) {
        $file = fopen(PAUSAS_CSV, 'a');
        $row = [
            'id_funcionario' => $funcionario->id,
            'nome_funcionario' => $funcionario->nome,
            'equipe' => $funcionario->equipe,
            'inicio_pausa' => $funcionario->inicio_pausa->format('c'),
            'fim_pausa' => $fim_pausa->format('c'),
            'duracao_segundos' => $duracao_real,
            'motivo_pausa' => $funcionario->motivo_pausa,
            'alerta_15min' => $alerta_15min ? 'True' : 'False',
            'alerta_20min' => $alerta_20min ? 'True' : 'False',
            'status_aprovacao' => $funcionario->status_aprovacao,
            'observacao_reuniao' => $funcionario->observacao_reuniao ?? ''
        ];
        fputcsv($file, $row);
        fclose($file);
    }

    public function obter_status() {
        $status = ["n1" => [], "n2" => []];

        foreach ($this->funcionarios as $funcionario) {
            $status_disp = $funcionario->status_disponibilidade();
            
            // Garantir que a equipe existe e está no formato correto
            $equipe = isset($funcionario->equipe) ? strtolower($funcionario->equipe) : 'n1';
            if ($equipe !== 'n1' && $equipe !== 'n2') {
                $equipe = 'n1'; // Fallback para n1 se equipe inválida
            }
            
            $info_funcionario = [
                "id" => $funcionario->id,
                "nome" => $funcionario->nome,
                "equipe" => $equipe,
                "em_pausa" => $funcionario->em_pausa,
                "tempo_pausa" => 0,
                "motivo_pausa" => $funcionario->motivo_pausa,
                "status_aprovacao" => $funcionario->status_aprovacao,
                "solicitacao_timestamp" => $funcionario->solicitacao_timestamp ? $funcionario->solicitacao_timestamp->format('c') : null,
                "ativo" => $funcionario->ativo ?? true,
                "jornada_entrada" => $funcionario->jornada_entrada ?? "08:00",
                "jornada_saida" => $funcionario->jornada_saida ?? "17:00",
                "almoco_inicio" => $funcionario->almoco_inicio ?? "12:00",
                "almoco_fim" => $funcionario->almoco_fim ?? "13:00",
                "disponibilidade" => $status_disp
            ];

            if ($funcionario->em_pausa && $funcionario->inicio_pausa) {
                $tempo_decorrido = (new DateTime())->getTimestamp() - $funcionario->inicio_pausa->getTimestamp();
                $info_funcionario["tempo_pausa"] = $tempo_decorrido;
            }

            $status[$equipe][] = $info_funcionario;
        }

        return $status;
    }

    public function obter_metricas() {
        $metricas = [
            "total_pausas_funcionario" => [],
            "duracao_total_funcionario" => [],
            "duracao_media_funcionario" => [],
            "total_pausas_equipe" => [],
            "duracao_total_equipe" => [],
            "duracao_media_equipe" => [],
            "pausas_excedidas" => [],
            "total_pausas_por_motivo" => [],
            "duracao_total_por_motivo" => [],
            "duracao_media_por_motivo" => [],
            "alertas_15min_funcionario" => [],
            "alertas_15min_equipe" => [],
            "alertas_20min_funcionario" => [],
            "alertas_20min_equipe" => [],
            "pausas_reuniao_aprovadas" => [],
            "pausas_reuniao_rejeitadas" => [],
            "pausas_reuniao_pendentes" => [],
            "pausas_detalhadas" => []
        ];

        if (!$this->pdo) {
            // Se não houver conexão com o banco, tentar ler do CSV (fallback ou legado)
            if (file_exists(PAUSAS_CSV)) {
                return $this->obter_metricas_csv($metricas);
            }
            return $metricas;
        }

        try {
            // Buscar dados do banco de dados
            $stmt = $this->pdo->query("SELECT * FROM pausas ORDER BY inicio_pausa DESC");
            $rows = $stmt->fetchAll();

            foreach ($rows as $row) {
                $this->processar_linha_metrica($metricas, $row);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar métricas do MySQL: " . $e->getMessage());
            // Fallback para CSV se o banco falhar
            if (file_exists(PAUSAS_CSV)) {
                return $this->obter_metricas_csv($metricas);
            }
        }

        // Calcular médias
        $this->calcular_medias_metricas($metricas);

        return $metricas;
    }

    private function processar_linha_metrica(&$metricas, $data) {
        $id_func = (int)$data['id_funcionario'];
        $nome_func = $data['nome_funcionario'];
        $equipe_func = $data['equipe'];
        $duracao = (int)$data['duracao_segundos'];
        $motivo = $data['motivo_pausa'] ?? 'Desconhecido';
        
        // Tratar booleanos do banco (0/1) ou string do CSV ('True'/'False')
        $alerta_15min = false;
        if (isset($data['alerta_15min'])) {
            $alerta_15min = ($data['alerta_15min'] === 'True' || $data['alerta_15min'] == 1);
        }
        
        $alerta_20min = false;
        if (isset($data['alerta_20min'])) {
            $alerta_20min = ($data['alerta_20min'] === 'True' || $data['alerta_20min'] == 1);
        }

        $status_aprovacao = $data['status_aprovacao'] ?? 'N/A';
        $inicio_pausa = $data['inicio_pausa'] ?? '';
        $fim_pausa = $data['fim_pausa'] ?? '';
        $observacao_reuniao = $data['observacao_reuniao'] ?? '';

        $func_key = "{$nome_func} (ID: {$id_func})";

        // Adicionar dados detalhados
        $pausa_detalhada = [
            'id_funcionario' => $id_func,
            'nome_funcionario' => $nome_func,
            'equipe' => $equipe_func,
            'inicio_pausa' => $inicio_pausa,
            'fim_pausa' => $fim_pausa,
            'duracao_segundos' => $duracao,
            'motivo_pausa' => $motivo,
            'alerta_15min' => $alerta_15min,
            'alerta_20min' => $alerta_20min,
            'status_aprovacao' => $status_aprovacao,
            'observacao_reuniao' => $observacao_reuniao,
            'excedeu_limite' => $duracao > $this->duracao_pausa_minutos * 60
        ];
        $metricas["pausas_detalhadas"][] = $pausa_detalhada;

        // Métricas por funcionário
        if (!isset($metricas["total_pausas_funcionario"][$func_key])) {
            $metricas["total_pausas_funcionario"][$func_key] = 0;
            $metricas["duracao_total_funcionario"][$func_key] = 0;
        }
        $metricas["total_pausas_funcionario"][$func_key]++;
        $metricas["duracao_total_funcionario"][$func_key] += $duracao;

        // Métricas por equipe
        if (!isset($metricas["total_pausas_equipe"][$equipe_func])) {
            $metricas["total_pausas_equipe"][$equipe_func] = 0;
            $metricas["duracao_total_equipe"][$equipe_func] = 0;
        }
        $metricas["total_pausas_equipe"][$equipe_func]++;
        $metricas["duracao_total_equipe"][$equipe_func] += $duracao;

        // Pausas excedidas
        if ($duracao > $this->duracao_pausa_minutos * 60) {
            if (!isset($metricas["pausas_excedidas"][$func_key])) {
                $metricas["pausas_excedidas"][$func_key] = 0;
            }
            $metricas["pausas_excedidas"][$func_key]++;
        }

        // Métricas por motivo
        if (!isset($metricas["total_pausas_por_motivo"][$motivo])) {
            $metricas["total_pausas_por_motivo"][$motivo] = 0;
            $metricas["duracao_total_por_motivo"][$motivo] = 0;
        }
        $metricas["total_pausas_por_motivo"][$motivo]++;
        $metricas["duracao_total_por_motivo"][$motivo] += $duracao;

        // Alertas 15min
        if ($alerta_15min) {
            if (!isset($metricas["alertas_15min_funcionario"][$func_key])) {
                $metricas["alertas_15min_funcionario"][$func_key] = 0;
            }
            $metricas["alertas_15min_funcionario"][$func_key]++;

            if (!isset($metricas["alertas_15min_equipe"][$equipe_func])) {
                $metricas["alertas_15min_equipe"][$equipe_func] = 0;
            }
            $metricas["alertas_15min_equipe"][$equipe_func]++;
        }

        // Alertas 20min
        if ($alerta_20min) {
            if (!isset($metricas["alertas_20min_funcionario"][$func_key])) {
                $metricas["alertas_20min_funcionario"][$func_key] = 0;
            }
            $metricas["alertas_20min_funcionario"][$func_key]++;

            if (!isset($metricas["alertas_20min_equipe"][$equipe_func])) {
                $metricas["alertas_20min_equipe"][$equipe_func] = 0;
            }
            $metricas["alertas_20min_equipe"][$equipe_func]++;
        }

        // Métricas de reunião
        if ($motivo == "Reunião") {
            if ($status_aprovacao == "aprovado") {
                if (!isset($metricas["pausas_reuniao_aprovadas"][$func_key])) {
                    $metricas["pausas_reuniao_aprovadas"][$func_key] = 0;
                }
                $metricas["pausas_reuniao_aprovadas"][$func_key]++;
            } elseif ($status_aprovacao == "rejeitado") {
                if (!isset($metricas["pausas_reuniao_rejeitadas"][$func_key])) {
                    $metricas["pausas_reuniao_rejeitadas"][$func_key] = 0;
                }
                $metricas["pausas_reuniao_rejeitadas"][$func_key]++;
            } elseif ($status_aprovacao == "pendente") {
                if (!isset($metricas["pausas_reuniao_pendentes"][$func_key])) {
                    $metricas["pausas_reuniao_pendentes"][$func_key] = 0;
                }
                $metricas["pausas_reuniao_pendentes"][$func_key]++;
            }
        }
    }

    private function obter_metricas_csv(&$metricas) {
        $file = fopen(PAUSAS_CSV, 'r');
        $headers = fgetcsv($file); // Pular cabeçalho

        while (($row = fgetcsv($file)) !== false) {
            $data = array_combine($headers, $row);
            $this->processar_linha_metrica($metricas, $data);
        }
        fclose($file);
        
        $this->calcular_medias_metricas($metricas);
        return $metricas;
    }

    private function calcular_medias_metricas(&$metricas) {
        foreach ($metricas["duracao_total_funcionario"] as $func => $total) {
            $total_pausas = $metricas["total_pausas_funcionario"][$func];
            if ($total_pausas > 0) {
                $metricas["duracao_media_funcionario"][$func] = $total / $total_pausas;
            }
        }

        foreach ($metricas["duracao_total_equipe"] as $equipe => $total) {
            $total_pausas = $metricas["total_pausas_equipe"][$equipe];
            if ($total_pausas > 0) {
                $metricas["duracao_media_equipe"][$equipe] = $total / $total_pausas;
            }
        }

        foreach ($metricas["duracao_total_por_motivo"] as $motivo => $total) {
            $total_pausas = $metricas["total_pausas_por_motivo"][$motivo];
            if ($total_pausas > 0) {
                $metricas["duracao_media_por_motivo"][$motivo] = $total / $total_pausas;
            }
        }
    }

    public function getFuncionario($id) {
        return $this->funcionarios[$id] ?? null;
    }

    public function getFuncionarios() {
        return $this->funcionarios;
    }

    public function salvar_estado() {
        $estado = ['funcionarios' => []];
        foreach ($this->funcionarios as $id => $func) {
            $estado['funcionarios'][$id] = [
                'em_pausa' => $func->em_pausa,
                'inicio_pausa' => $func->inicio_pausa ? $func->inicio_pausa->format('c') : null,
                'motivo_pausa' => $func->motivo_pausa,
                'status_aprovacao' => $func->status_aprovacao,
                'solicitacao_timestamp' => $func->solicitacao_timestamp ? $func->solicitacao_timestamp->format('c') : null,
                'observacao_reuniao' => $func->observacao_reuniao
            ];
        }
        // [VULN-018] LOCK_EX para evitar race condition em escritas concorrentes
        file_put_contents(
            ESTADO_JSON,
            json_encode($estado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    // Métodos para administração
    public function obter_funcionarios() {
        return $this->funcionarios;
    }

    public function remover_funcionario($funcionario_id) {
        if (isset($this->funcionarios[$funcionario_id])) {
            $this->funcionarios[$funcionario_id]->ativo = false;
            $this->salvar_estado();
            return ["sucesso" => true, "mensagem" => "Funcionário desativado com sucesso."];
        }
        return ["sucesso" => false, "mensagem" => "Funcionário não encontrado."];
    }

    public function atualizar_funcionario($funcionario_id, $nome, $equipe, $jornada_entrada = null, $jornada_saida = null, $almoco_inicio = null, $almoco_fim = null, $ativo = null, $ad_login = null) {
        if (isset($this->funcionarios[$funcionario_id])) {
            $func = $this->funcionarios[$funcionario_id];
            $func->nome = $nome;
            $func->equipe = $equipe;
            
            // Atualizar horários se fornecidos
            if ($jornada_entrada !== null) $func->jornada_entrada = $jornada_entrada;
            if ($jornada_saida !== null) $func->jornada_saida = $jornada_saida;
            if ($almoco_inicio !== null) $func->almoco_inicio = $almoco_inicio;
            if ($almoco_fim !== null) $func->almoco_fim = $almoco_fim;
            if ($ativo !== null) $func->ativo = $ativo;
            $func->ad_login = $ad_login;
            
            $this->salvar_estado();
            return ["sucesso" => true, "mensagem" => "Funcionário atualizado com sucesso."];
        }
        return ["sucesso" => false, "mensagem" => "Funcionário não encontrado."];
    }

    public function atualizar_configuracoes($limite_pausa, $duracao_minutos) {
        $this->limite_pausa_por_equipe = $limite_pausa;
        $this->duracao_pausa_minutos = $duracao_minutos;
        return ["sucesso" => true, "mensagem" => "Configurações atualizadas com sucesso."];
    }
}

