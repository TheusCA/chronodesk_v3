<?php
require_once __DIR__ . '/Funcionario.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../services/PauseRules.php';
require_once __DIR__ . '/../services/PresenceEngine.php';
require_once __DIR__ . '/../services/PresenceRepository.php';

class GerenciadorPausas {
    private $funcionarios;
    public $limite_pausa_por_equipe;
    private $duracao_pausa_minutos;
    private $pdo;
    private $estado_pausas_lido = false;
    // [AD-F9-1] Relogio fixo so para os QAs (null = agora).
    private $relogio_fixo = null;

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
        // [F7, DV6] Sem arquivo = nenhuma pausa ainda (lido); arquivo ilegivel
        // = pausas indisponiveis, para a Visao Operacional nao contar "nenhuma
        // pausa" em silencio.
        $this->estado_pausas_lido = !is_file(ESTADO_JSON);
        // LOCK_SH no proprio arquivo: ver ler_estado_pausas() em config.php.
        $estado = ler_estado_pausas(ESTADO_JSON);
        if ($estado === null) return;
        $this->estado_pausas_lido = true;

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

        // [F9, 11.3] Motivo conferido com a equipe do cadastro (nunca a do
        // cliente): "Almoço" so no Critico.
        if (validate_motivo_pausa($motivo, $funcionario->equipe) === null) {
            return ["sucesso" => false, "codigo" => "motivo_da_equipe", "mensagem" => PauseRules::LUNCH_ONLY_CRITICAL_MESSAGE];
        }

        // [AD-F9-1, 9.15.17] Critico e Hibrido: pelo menos 1 disponivel na
        // equipe (toda pausa conta); N1 e N2: o limite simultaneo de hoje.
        $equipe_pausa = PortalTeams::normalize($funcionario->equipe);
        if (PauseRules::usesAvailabilityRule($equipe_pausa)) {
            $recusa = $this->recusa_por_disponibilidade($funcionario);
            if ($recusa !== null) {
                return $recusa;
            }
        } elseif ($this->pausa_conta_para_limite($motivo)) {
            $pausas_ativas_equipe = 0;
            foreach ($this->funcionarios as $f) {
                if ($f->equipe == $funcionario->equipe && $f->em_pausa) {
                    if ($this->pausa_conta_para_limite($f->motivo_pausa)) {
                        $pausas_ativas_equipe++;
                    }
                }
            }

            if ($pausas_ativas_equipe >= $this->limite_pausa_por_equipe) {
                $equipe_nome = strtoupper($funcionario->equipe);
                return ["sucesso" => false, "mensagem" => "Limite de pausas simultâneas atingido para a equipe {$equipe_nome}. Atualmente há {$pausas_ativas_equipe} pausas ativas. Máximo permitido: {$this->limite_pausa_por_equipe} pausas."];
            }
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

        // [AD-F9-2] Reuniao sem aprovacao no N2, no Critico e no Hibrido; nas
        // duas ultimas, sujeita a regra de pelo menos 1 disponivel.
        $equipe_pausa = PortalTeams::normalize($funcionario->equipe);
        if ($this->equipe_normalizada($funcionario->equipe) === 'n2' || PauseRules::usesAvailabilityRule($equipe_pausa)) {
            if (PauseRules::usesAvailabilityRule($equipe_pausa)) {
                $recusa = $this->recusa_por_disponibilidade($funcionario);
                if ($recusa !== null) {
                    return $recusa;
                }
            }
            $funcionario->em_pausa = true;
            $funcionario->inicio_pausa = new DateTime();
            $funcionario->motivo_pausa = $motivo;
            $funcionario->status_aprovacao = 'aprovado';
            $funcionario->solicitacao_timestamp = null;
            $funcionario->observacao_reuniao = $observacao;
            $this->salvar_estado();

            $hora = $funcionario->inicio_pausa->format('H:i:s');
            return [
                "sucesso" => true,
                "approval_required" => false,
                "mensagem" => "{$funcionario->nome} iniciou a pausa de reuniao as {$hora}.",
            ];
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

    public function finalizar_pausa($funcionario_id, array $contexto = []) {
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

        // Alertas de 15 e 20 min (so o que conta no limite; nunca o almoco) e o
        // limite aplicado, gravado na pausa (F9, D-F9-7).
        [$alerta_15min, $alerta_20min] = PauseRules::alerts($funcionario->motivo_pausa, $duracao_real);
        $limite_segundos = $this->limite_aplicado_segundos($funcionario);

        $persistido = false;

        // Registrar a pausa no Banco de Dados (MySQL)
        if ($this->pdo) {
            try {
                $sql = "INSERT INTO pausas (
                    id_funcionario, nome_funcionario, equipe, inicio_pausa, fim_pausa, 
                    duracao_segundos, motivo_pausa, alerta_15min, alerta_20min, limite_segundos,
                    status_aprovacao, observacao_reuniao
                ) VALUES (
                    :id_funcionario, :nome_funcionario, :equipe, :inicio_pausa, :fim_pausa,
                    :duracao_segundos, :motivo_pausa, :alerta_15min, :alerta_20min, :limite_segundos,
                    :status_aprovacao, :observacao_reuniao
                )";
                
                $stmt = $this->pdo->prepare($sql);
                $observacaoHistorico = $this->observacao_historico_pausa($funcionario, $contexto);
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
                    ':limite_segundos' => $limite_segundos,
                    ':status_aprovacao' => $funcionario->status_aprovacao,
                    ':observacao_reuniao' => $observacaoHistorico
                ]);
                $persistido = true;
            } catch (PDOException $e) {
                error_log("Erro ao salvar pausa no MySQL; tentando fallback CSV.");
                $persistido = $this->salvar_csv_backup(
                    $funcionario,
                    $fim_pausa,
                    $duracao_real,
                    $alerta_15min,
                    $alerta_20min,
                    $contexto
                );
            }
        } else {
            // Se não houver conexão, salvar no CSV
            $persistido = $this->salvar_csv_backup(
                $funcionario,
                $fim_pausa,
                $duracao_real,
                $alerta_15min,
                $alerta_20min,
                $contexto
            );
        }

        if (!$persistido) {
            error_log('[PAUSA] Historico indisponivel; pausa ativa foi preservada.');
            return [
                "sucesso" => false,
                "mensagem" => "Não foi possível registrar o histórico. A pausa continua ativa; tente novamente.",
            ];
        }

        $detalhes = [
            'funcionario_id' => (int)$funcionario->id,
            'funcionario_nome' => $funcionario->nome,
            'funcionario_equipe' => $funcionario->equipe,
            'motivo_pausa' => $funcionario->motivo_pausa,
            'inicio_pausa' => $funcionario->inicio_pausa->format('Y-m-d H:i:s'),
            'fim_pausa' => $fim_pausa->format('Y-m-d H:i:s'),
            'duracao_segundos' => $duracao_real,
        ];

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

        if (PauseRules::exceeded($detalhes['motivo_pausa'], $duracao_real, $limite_segundos, (int)$this->duracao_pausa_minutos)) {
            $minutos_limite = intdiv((int)$limite_segundos, 60);
            $mensagem .= " (Excedeu o tempo limite de {$minutos_limite} minutos!)";
        }

        if ($alerta_20min) {
            $mensagem .= " 🚨 ALERTA CRÍTICO - Pausa excedeu 20 minutos!";
        } elseif ($alerta_15min) {
            $mensagem .= " ⚠️ ATENÇÃO - Pausa excedeu 15 minutos!";
        }

        return ["sucesso" => true, "mensagem" => $mensagem, "detalhes" => $detalhes];
    }

    private function observacao_historico_pausa($funcionario, array $contexto): string {
        $observacao = trim((string)($funcionario->observacao_reuniao ?? ''));
        if (($contexto['origem'] ?? '') !== 'admin_force_end') {
            return $observacao;
        }

        $manual = 'Derrubada manual por admin: ' . ($contexto['admin'] ?? 'admin');
        if (($contexto['justificativa'] ?? '') !== '') {
            $manual .= ' | Justificativa: ' . $contexto['justificativa'];
        }
        return trim($observacao === '' ? $manual : $observacao . ' | ' . $manual);
    }

    private function salvar_csv_backup($funcionario, $fim_pausa, $duracao_real, $alerta_15min, $alerta_20min, array $contexto = []): bool {
        $file = @fopen(PAUSAS_CSV, 'a');
        if ($file === false || !flock($file, LOCK_EX)) {
            if (is_resource($file)) {
                fclose($file);
            }
            return false;
        }
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
            'observacao_reuniao' => $this->observacao_historico_pausa($funcionario, $contexto)
        ];
        try {
            $written = fputcsv($file, $row, ',', '"', '\\');
            return $written !== false && fflush($file);
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    public function obter_status() {
        $serverNow = new DateTime();
        // [F9, D-F9-3] Uma chave por equipe de tecnico (n1, n2, critico e
        // hibrido), aditivo: as chaves n1 e n2 continuam iguais.
        $status = array_fill_keys(PortalTeams::WORK_TEAMS, []) + [
            "server_now" => $serverNow->format('c'),
            "poll_interval_seconds" => 15,
        ];

        foreach ($this->funcionarios as $funcionario) {
            // [F4, L-07] Inativo (arquivado ou antigo) fica fora do status.php
            // e dos contadores do dashboard.php, que saem daqui.
            if (!($funcionario->ativo ?? true)) {
                continue;
            }
            $status_disp = $funcionario->status_disponibilidade();

            // Equipe de tecnico pela lista unica; Lideranca e desconhecida ficam fora.
            $equipe = isset($funcionario->equipe) ? PortalTeams::normalize($funcionario->equipe) : PortalTeams::N1;
            if (!PortalTeams::isWorkTeam($equipe)) {
                continue;
            }
            $info_funcionario = [
                "id" => $funcionario->id,
                "nome" => $funcionario->nome,
                "equipe" => $equipe,
                "em_pausa" => $funcionario->em_pausa,
                "tempo_pausa" => 0,
                "elapsed_seconds" => 0,
                "inicio_pausa" => $funcionario->inicio_pausa ? $funcionario->inicio_pausa->format('c') : null,
                // [F9] 0 = sem limite (Pessoal, Reuniao e o almoco sem intervalo valido).
                "duracao_limite_segundos" => $this->limite_aplicado_segundos($funcionario) ?? 0,
                "motivo_pausa" => $funcionario->motivo_pausa,
                "status_aprovacao" => $funcionario->status_aprovacao,
                "solicitacao_timestamp" => $funcionario->solicitacao_timestamp ? $funcionario->solicitacao_timestamp->format('c') : null,
                "observacao_reuniao" => $funcionario->observacao_reuniao,
                "ativo" => $funcionario->ativo ?? true,
                "jornada_entrada" => $funcionario->jornada_entrada ?? "08:00",
                "jornada_saida" => $funcionario->jornada_saida ?? "17:00",
                "almoco_inicio" => $funcionario->almoco_inicio ?? "12:00",
                "almoco_fim" => $funcionario->almoco_fim ?? "13:00",
                "disponibilidade" => $status_disp
            ];

            if ($funcionario->em_pausa && $funcionario->inicio_pausa) {
                $tempo_decorrido = $serverNow->getTimestamp() - $funcionario->inicio_pausa->getTimestamp();
                $info_funcionario["tempo_pausa"] = $tempo_decorrido;
                $info_funcionario["elapsed_seconds"] = $tempo_decorrido;
            }

            $status[$equipe][] = $info_funcionario;
        }

        // [AD-F9-1, D-F9-28] Destaque "sem ninguem disponivel" (aditivo).
        $status["disponibilidade_equipes"] = $this->disponibilidade_equipes($this->relogio_fixo ?? DateTimeImmutable::createFromMutable($serverNow));

        return $status;
    }

    /**
     * [F7, DV11] Texto livre da pausa de reuniao (observacao_reuniao) so para
     * a gestao e o proprio, regra do SEC-07b; os demais recebem o item sem a
     * chave. $viewer: portal_actor() de quem consulta; sem employee_id, so a
     * gestao ve.
     */
    public static function status_para_o_perfil(array $status, array $viewer): array {
        if (portal_role_is_manager($viewer['role'] ?? null)) {
            return $status;
        }
        $proprio = (int)($viewer['employee_id'] ?? 0);
        foreach (PortalTeams::WORK_TEAMS as $equipe) {
            foreach ($status[$equipe] ?? [] as $indice => $item) {
                if ($proprio <= 0 || (int)($item['id'] ?? 0) !== $proprio) {
                    unset($status[$equipe][$indice]['observacao_reuniao']);
                }
            }
        }
        return $status;
    }

    /**
     * $completo = false (tela de métricas): até METRICS_MAX_ROWS pausas mais
     * recentes, com aviso em "dados_truncados".
     * $completo = true (exportação, PERF-02): todas as pausas, lidas em páginas.
     * Com $ao_detalhar, cada pausa detalhada vai para o callback em vez de ficar
     * acumulada em "pausas_detalhadas": a exportação grava linha a linha.
     */
    public function obter_metricas(bool $completo = false, ?callable $ao_detalhar = null) {
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
            "pausas_detalhadas" => [],
            "dados_truncados" => false,
            "limite_registros" => 0,
        ];

        if (!$this->pdo) {
            // Se não houver conexão com o banco, tentar ler do CSV (fallback ou legado)
            if (file_exists(PAUSAS_CSV)) {
                return $this->obter_metricas_csv($metricas, $ao_detalhar);
            }
            return $metricas;
        }

        if ($completo) {
            try {
                $pausas = db_keyset_iterate(function (?array $ultima, int $pagina): array {
                    $sql = "SELECT id, id_funcionario, nome_funcionario, equipe, inicio_pausa, fim_pausa,
                                   duracao_segundos, motivo_pausa, alerta_15min, alerta_20min, limite_segundos,
                                   status_aprovacao, observacao_reuniao
                            FROM pausas";
                    $params = [];
                    if ($ultima !== null) {
                        $sql .= " WHERE inicio_pausa < :cursor_inicio
                                  OR (inicio_pausa = :cursor_mesmo_inicio AND id < :cursor_id)";
                        $params = [
                            ':cursor_inicio' => $ultima['inicio_pausa'],
                            ':cursor_mesmo_inicio' => $ultima['inicio_pausa'],
                            ':cursor_id' => (int)$ultima['id'],
                        ];
                    }
                    $stmt = $this->pdo->prepare($sql . ' ORDER BY inicio_pausa DESC, id DESC LIMIT ' . $pagina);
                    $stmt->execute($params);
                    return $stmt->fetchAll();
                }, 1000);
                foreach ($pausas as $row) {
                    $this->processar_linha_metrica($metricas, $row, $ao_detalhar);
                }
            } catch (PDOException $e) {
                // Sem fallback parcial: exportação incompleta não pode sair como se fosse completa.
                error_log("Erro ao exportar pausas do MySQL: " . $e->getMessage());
                throw new RuntimeException('Nao foi possivel ler todas as pausas.', 0, $e);
            }
            $this->calcular_medias_metricas($metricas);
            return $metricas;
        }

        try {
            $limit = max(100, min((int)(getenv('METRICS_MAX_ROWS') ?: 10000), 50000));
            $metricas["limite_registros"] = $limit;
            $stmt = $this->pdo->prepare(
                "SELECT id_funcionario, nome_funcionario, equipe, inicio_pausa, fim_pausa,
                        duracao_segundos, motivo_pausa, alerta_15min, alerta_20min, limite_segundos,
                        status_aprovacao, observacao_reuniao
                 FROM pausas
                 ORDER BY inicio_pausa DESC
                 LIMIT :limit"
            );
            $stmt->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            if (count($rows) > $limit) {
                $metricas["dados_truncados"] = true;
                $rows = array_slice($rows, 0, $limit);
            }

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

    private function processar_linha_metrica(&$metricas, $data, ?callable $ao_detalhar = null) {
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
        // [F9, D-F9-7] Excedeu pelo limite gravado na pausa; as antigas (sem
        // limite gravado), pela configuracao atual; o almoco sem limite, nunca.
        $limite_gravado = $data['limite_segundos'] ?? null;
        $excedeu = PauseRules::exceeded($motivo, $duracao, $limite_gravado, (int)$this->duracao_pausa_minutos);

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
            'excedeu_limite' => $excedeu,
            'limite_segundos' => $limite_gravado === null || $limite_gravado === '' ? null : (int)$limite_gravado,
        ];
        if ($ao_detalhar !== null) {
            $ao_detalhar($pausa_detalhada);
        } else {
            $metricas["pausas_detalhadas"][] = $pausa_detalhada;
        }

        // [F9, 11.3 tratamento 3] O almoco do Critico fica fora dos totais e
        // medias por funcionario e por equipe; continua em "por motivo".
        if (PauseRules::countsInTotals($motivo)) {
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
        }

        // Pausas excedidas
        if ($excedeu) {
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

    private function obter_metricas_csv(&$metricas, ?callable $ao_detalhar = null) {
        $file = fopen(PAUSAS_CSV, 'r');
        $headers = fgetcsv($file, null, ',', '"', '\\'); // Pular cabeçalho

        while (($row = fgetcsv($file, null, ',', '"', '\\')) !== false) {
            $data = array_combine($headers, $row);
            $this->processar_linha_metrica($metricas, $data, $ao_detalhar);
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

    /**
     * [F7, DV2 e DV6] Pausa de cada cadastro carregado, do estado ja lido pelo
     * init.php (sem segunda leitura do arquivo): id => ['em_pausa' => bool,
     * 'motivo' => ?string]. null quando o estado de pausas estava ilegivel.
     */
    public function pausas_atuais(): ?array {
        if (!$this->estado_pausas_lido) {
            return null;
        }
        $pausas = [];
        foreach ($this->funcionarios as $id => $func) {
            $pausas[(int)$id] = [
                'em_pausa' => $func->em_pausa === true,
                'motivo' => $func->motivo_pausa,
                // [F9, 11.3] Inicio (HH:MM) para o detalhe do almoco do Critico.
                'inicio' => $func->em_pausa === true && $func->inicio_pausa ? $func->inicio_pausa->format('H:i') : null,
            ];
        }
        return $pausas;
    }

    /**
     * [F9, D-F9-6 e D-F9-16 revistas] Limite de tempo aplicado a pausa do
     * cadastro, em segundos (null = sem limite): cafe pela configuracao; almoco
     * do Critico pelo intervalo de almoco do cadastro ate o F8 (contrato);
     * Pessoal e Reuniao sem limite.
     */
    private function limite_aplicado_segundos($funcionario): ?int {
        return PauseRules::appliedLimitSeconds(
            $funcionario->motivo_pausa,
            (int)$this->duracao_pausa_minutos,
            PauseRules::lunchIntervalSeconds($funcionario->almoco_inicio ?? null, $funcionario->almoco_fim ?? null),
            null
        );
    }

    /** [AD-F9-1] Para os QAs: fixa o "agora" da regra de disponibilidade. */
    public function usar_relogio(?DateTimeImmutable $agora): void {
        $this->relogio_fixo = $agora;
    }

    private function agora(): DateTimeImmutable {
        return $this->relogio_fixo ?? new DateTimeImmutable('now');
    }

    /**
     * [AD-F9-1, 9.15.17] Recusa (409 na API) se a pausa deixaria a equipe sem
     * ninguem disponivel, exceto quem esta sozinho no turno. Roda dentro da
     * trava do estado das pausas (with_pause_state_lock), como o limite de hoje.
     */
    private function recusa_por_disponibilidade($funcionario): ?array {
        $equipe = PortalTeams::normalize($funcionario->equipe);
        $decisao = PauseRules::availabilityDecision($this->membros_disponibilidade($equipe, $this->agora()), (int)$funcionario->id);
        if ($decisao['allowed']) {
            return null;
        }
        return ["sucesso" => false, "codigo" => "equipe_sem_disponivel", "mensagem" => PauseRules::availabilityMessage($equipe)];
    }

    /**
     * [AD-F9-1] Membros ativos da equipe: id => na jornada do dia (a funcao
     * unica da 9.15.6), em pausa (almoco incluido) e com ausencia ativa agora.
     */
    private function membros_disponibilidade(string $equipe, DateTimeImmutable $agora): array {
        $membros = array_filter($this->funcionarios, static fn ($f): bool => ($f->ativo ?? true) && PortalTeams::normalize($f->equipe) === $equipe);
        $ausentes = $this->ausentes_agora($membros, $agora);
        $dia = $agora->format('Y-m-d');
        $hora = $agora->format('H:i');
        $situacao = [];
        foreach ($membros as $f) {
            $jornada = PresenceRepository::shiftFor(['work_start' => $f->jornada_entrada, 'work_end' => $f->jornada_saida], $dia);
            $situacao[(int)$f->id] = [
                'in_shift' => PauseRules::inShift($jornada['work_start'], $jornada['work_end'], $hora),
                'paused' => $f->em_pausa === true,
                'absent' => isset($ausentes[(int)$f->id]),
            ];
        }
        return $situacao;
    }

    /**
     * [AD-F9-1] Quem tem ausencia lancada ativa agora (id => true), pela regra
     * do motor (dia inteiro, "a partir de", intervalo e excecao antiga). Sem
     * banco ou com falha: ninguem, com registro no log (D-F9-26).
     */
    private function ausentes_agora(array $membros, DateTimeImmutable $agora): array {
        if ($membros === []) {
            return [];
        }
        if (!$this->pdo) {
            error_log('[PAUSA] Ausencias indisponiveis (sem banco) para a regra de disponibilidade.');
            return [];
        }
        $dia = $agora->format('Y-m-d');
        try {
            $repositorio = new PresenceRepository($this->pdo);
            $excecoes = $repositorio->exceptionsByDay($dia, $dia);
            $ausencias = $repositorio->absencesByEmployee($dia, $dia);
        } catch (Throwable $e) {
            error_log('[PAUSA] Ausencias indisponiveis para a regra de disponibilidade: ' . get_class($e));
            return [];
        }
        $ausentes = [];
        foreach ($membros as $f) {
            $id = (int)$f->id;
            $itens = [];
            if (in_array($excecoes[$id . ':' . $dia]['exception_type'] ?? null, PresenceEngine::ABSENCE_EXCEPTIONS, true)) {
                $itens[] = ['origem' => 'excecao', 'period_type' => 'full_day'];
            }
            foreach ($ausencias[$id] ?? [] as $linha) {
                if ($linha['starts_on'] <= $dia && $linha['ends_on'] >= $dia) {
                    $itens[] = ['origem' => 'ausencia', 'period_type' => $linha['period_type'], 'start_time' => $linha['start_time'], 'end_time' => $linha['end_time']];
                }
            }
            if ($itens === []) {
                continue;
            }
            $colaborador = PresenceRepository::shiftFor(['id' => $id, 'work_start' => $f->jornada_entrada, 'work_end' => $f->jornada_saida], $dia);
            if (PresenceEngine::nowPresence($colaborador, $agora, [], null, $itens, null)['situacao'] === 'ausente') {
                $ausentes[$id] = true;
            }
        }
        return $ausentes;
    }

    /**
     * [AD-F9-1, D-F9-28] Situacao do Critico e do Hibrido para o destaque
     * "sem ninguem disponivel": so as equipes com cadastro (sem cadastro,
     * nenhuma consulta a mais no polling).
     */
    public function disponibilidade_equipes(?DateTimeImmutable $agora = null): array {
        $agora = $agora ?? $this->agora();
        $situacao = [];
        foreach ([PortalTeams::CRITICAL, PortalTeams::HYBRID] as $equipe) {
            $membros = $this->membros_disponibilidade($equipe, $agora);
            if ($membros !== []) {
                $situacao[$equipe] = PauseRules::teamAvailability($membros);
            }
        }
        return $situacao;
    }

    private function pausa_conta_para_limite($motivo): bool {
        return self::motivo_conta_para_limite($motivo);
    }

    /**
     * Motivo que conta no limite simultaneo da equipe e nos alertas de 15/20
     * min (Pessoal, Reunião e, no F9, o Almoço do Crítico nao contam; D-F9-5).
     */
    public static function motivo_conta_para_limite($motivo): bool {
        return PauseRules::countsForTeamLimit($motivo);
    }

    private function equipe_normalizada($equipe): string {
        $value = strtolower(trim((string)$equipe));
        $value = str_replace([' ', '-', '_'], '', $value);
        if ($value === 'n2' || $value === 'nivel2') {
            return 'n2';
        }
        return 'n1';
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

    public function atualizar_funcionario($funcionario_id, $nome, $equipe, $jornada_entrada = null, $jornada_saida = null, $almoco_inicio = null, $almoco_fim = null, $ativo = null, $ad_login = null, $access_role = null) {
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
            if ($access_role !== null) $func->access_role = $access_role;

            // [F4, L-11] Sem salvar_estado(): o estado.json so guarda a pausa, e
            // regrava-lo daqui, fora do lock, podia desfazer uma pausa iniciada
            // no mesmo instante.
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

