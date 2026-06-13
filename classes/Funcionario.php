<?php
class Funcionario {
    public $id;
    public $nome;
    public $equipe;
    public $em_pausa;
    public $inicio_pausa;
    public $motivo_pausa;
    public $status_aprovacao;
    public $solicitacao_timestamp;
    public $observacao_reuniao;
    
    // Controle de horário
    public $jornada_entrada;      // Horário de entrada (ex: "08:00")
    public $jornada_saida;        // Horário de saída (ex: "17:00")
    public $almoco_inicio;        // Início do horário de almoço (ex: "12:00")
    public $almoco_fim;           // Fim do horário de almoço (ex: "13:00")
    public $ativo;                // Se o funcionário está ativo (true) ou inativo (false)
    public $ad_login;             // Login vinculado no Active Directory
    public $access_role;

    public function __construct($id, $nome, $equipe, $jornada_entrada = "08:00", $jornada_saida = "17:00", $almoco_inicio = "12:00", $almoco_fim = "13:00", $ativo = true, $ad_login = null, $access_role = 'tecnico') {
        $this->id = $id;
        $this->nome = $nome;
        $this->equipe = $equipe;
        $this->em_pausa = false;
        $this->inicio_pausa = null;
        $this->motivo_pausa = null;
        $this->status_aprovacao = null;
        $this->solicitacao_timestamp = null;
        $this->observacao_reuniao = null;
        
        // Controle de horário
        $this->jornada_entrada = $jornada_entrada;
        $this->jornada_saida = $jornada_saida;
        $this->almoco_inicio = $almoco_inicio;
        $this->almoco_fim = $almoco_fim;
        $this->ativo = $ativo;
        $this->ad_login = $ad_login;
        $this->access_role = $access_role;
    }
    
    /**
     * Verifica se o funcionário está disponível no momento atual
     * Considera a jornada de trabalho e o horário de almoço
     */
    public function esta_disponivel($hora_atual = null) {
        if (!$this->ativo) {
            return false; // Funcionário inativo
        }
        
        if ($hora_atual === null) {
            $hora_atual = new DateTime();
        } elseif (is_string($hora_atual)) {
            $hora_atual = DateTime::createFromFormat('H:i', $hora_atual);
            if ($hora_atual === false) {
                // Se não conseguir criar DateTime da string, usar hora atual
                $hora_atual = new DateTime();
            }
        }
        
        // Obter hora atual no formato HH:MM
        $hora_str = $hora_atual->format('H:i');
        
        // Verificar se está dentro da jornada de trabalho
        $dentro_jornada = ($hora_str >= $this->jornada_entrada && $hora_str < $this->jornada_saida);
        
        if (!$dentro_jornada) {
            return false; // Fora da jornada de trabalho
        }
        
        // Verificar se está no horário de almoço (considerar como indisponível durante almoço)
        $no_almoco = ($hora_str >= $this->almoco_inicio && $hora_str < $this->almoco_fim);
        
        if ($no_almoco) {
            return false; // No horário de almoço (indisponível para pausas)
        }
        
        return true; // Disponível para pausas
    }
    
    /**
     * Retorna o status de disponibilidade como texto
     */
    public function status_disponibilidade() {
        if (!$this->ativo) {
            return ['status' => 'inativo', 'label' => 'Inativo', 'cor' => '#999'];
        }
        
        $agora = new DateTime();
        $disponivel = $this->esta_disponivel($agora);
        
        if ($disponivel) {
            return ['status' => 'disponivel', 'label' => 'Disponível', 'cor' => '#10b981'];
        } else {
            $hora_str = $agora->format('H:i');
            if ($hora_str < $this->jornada_entrada) {
                return ['status' => 'antes_jornada', 'label' => 'Antes da jornada', 'cor' => '#f59e0b'];
            } elseif ($hora_str >= $this->jornada_saida) {
                return ['status' => 'apos_jornada', 'label' => 'Após a jornada', 'cor' => '#f59e0b'];
            } elseif ($hora_str >= $this->almoco_inicio && $hora_str < $this->almoco_fim) {
                return ['status' => 'almoco', 'label' => 'Horário de almoço', 'cor' => '#ef4444'];
            } else {
                return ['status' => 'indisponivel', 'label' => 'Indisponível', 'cor' => '#999'];
            }
        }
    }
}

