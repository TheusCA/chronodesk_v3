# Gerenciador de Pausas para Café - Versão Web com Métricas

Esta é uma aplicação web completa para gerenciar as pausas para café de uma equipe híbrida de 16 funcionários, garantindo que o número de funcionários em pausa simultaneamente por equipe não exceda um limite definido. Agora com sistema de métricas e persistência de dados.

## Funcionalidades

- **Interface Web Moderna**: Interface responsiva e intuitiva com design profissional
- **Controle de Pausas**: Iniciar e finalizar pausas para funcionários via interface web
- **Limite por Equipe**: Controlar o limite de funcionários em pausa por equipe (padrão: 2)
- **Monitoramento de Tempo**: Acompanhar a duração das pausas em tempo real
- **Alertas de Tempo**: Alertar se o tempo limite for excedido (padrão: 20 minutos)
- **Status Visual**: Visualizar o status atual das pausas por equipe com cores diferenciadas
- **Atualização Automática**: Status atualizado automaticamente a cada 30 segundos
- **Persistência de Dados**: Todas as pausas são registradas em arquivo CSV
- **Métricas Detalhadas**: Análise completa das pausas por funcionário e equipe
- **API REST**: Backend com API REST para integração com outros sistemas

## Novas Funcionalidades de Métricas

### Dados Salvos Automaticamente
- **ID do funcionário**
- **Nome do funcionário**
- **Equipe (N1 ou N2)**
- **Data e hora de início da pausa**
- **Data e hora de fim da pausa**
- **Duração total da pausa em segundos**

### Métricas Disponíveis
- **Total de pausas por funcionário**
- **Duração total de pausas por funcionário**
- **Duração média de pausas por funcionário**
- **Total de pausas por equipe**
- **Duração total de pausas por equipe**
- **Duração média de pausas por equipe**
- **Número de pausas que excederam o tempo limite**

## Estrutura do Projeto

```
pausa_cafe_web/
├── app.py                 # Servidor Flask (backend)
├── pausas.csv            # Arquivo de dados das pausas (criado automaticamente)
├── templates/
│   ├── index.html        # Interface web principal
│   └── metricas.html     # Página de métricas
├── static/
│   ├── css/
│   │   └── style.css     # Estilos da aplicação
│   └── js/
│   │       ├── script.js     # Lógica JavaScript principal
│   │       └── metricas.js   # Lógica JavaScript das métricas
├── requirements.txt      # Dependências Python
└── README.md             # Esta documentação
```

## Pré-requisitos

- Python 3.7 ou superior
- Flask
- Flask-CORS

## Instalação

1. **Instalar dependências:**
   ```bash
   pip install -r requirements.txt
   ```
   
   Ou instalar manualmente:
   ```bash
   pip install flask flask-cors
   ```

2. **Executar a aplicação:**
   ```bash
   cd pausa_cafe_web
   python app.py
   ```

3. **Acessar a aplicação:**
   - Interface principal: `http://localhost:5000`
   - Página de métricas: `http://localhost:5000/metricas`

## Como Usar

### Interface Web Principal

1. **Iniciar Pausa:**
   - Digite o ID do funcionário (1-16) no campo "Iniciar Pausa"
   - Clique no botão "Iniciar"
   - O sistema verificará se o limite da equipe não foi atingido

2. **Finalizar Pausa:**
   - Digite o ID do funcionário no campo "Finalizar Pausa"
   - Clique no botão "Finalizar"
   - O sistema mostrará a duração total da pausa
   - **A pausa será automaticamente registrada no arquivo `pausas.csv`**

3. **Visualizar Status:**
   - O status é atualizado automaticamente a cada 30 segundos
   - Funcionários em pausa aparecem com fundo vermelho
   - Funcionários disponíveis aparecem com fundo verde
   - O tempo de pausa é exibido em tempo real

4. **Ver Métricas:**
   - Clique no botão "📊 Ver Métricas" na interface principal
   - Acesse análises detalhadas das pausas

### Página de Métricas

A página de métricas oferece uma visão completa do histórico de pausas:

- **Análise por Funcionário**: Quantas pausas cada funcionário fez e por quanto tempo
- **Análise por Equipe**: Comparação entre as equipes N1 e N2
- **Identificação de Padrões**: Funcionários que frequentemente excedem o tempo limite
- **Duração Média**: Tempo médio de pausa por funcionário e equipe

### Organização das Equipes

- **Equipe N1**: Funcionários 1-8
- **Equipe N2**: Funcionários 9-16

### Regras de Negócio

- Máximo 2 funcionários em pausa simultaneamente por equipe
- Tempo limite de pausa: 20 minutos (com alerta se excedido)
- Não é possível iniciar pausa se o funcionário já estiver em pausa
- Não é possível finalizar pausa se o funcionário não estiver em pausa
- **Todas as pausas finalizadas são automaticamente registradas**

## API REST

A aplicação expõe uma API REST para integração:

### Endpoints

- `GET /api/status` - Obter status de todos os funcionários
- `GET /api/metricas` - Obter métricas completas das pausas
- `POST /api/iniciar_pausa` - Iniciar pausa para um funcionário
- `POST /api/finalizar_pausa` - Finalizar pausa para um funcionário

### Exemplos de Uso da API

**Obter Status:**
```bash
curl http://localhost:5000/api/status
```

**Obter Métricas:**
```bash
curl http://localhost:5000/api/metricas
```

**Iniciar Pausa:**
```bash
curl -X POST http://localhost:5000/api/iniciar_pausa \
  -H "Content-Type: application/json" \
  -d '{"funcionario_id": 1}'
```

**Finalizar Pausa:**
```bash
curl -X POST http://localhost:5000/api/finalizar_pausa \
  -H "Content-Type: application/json" \
  -d '{"funcionario_id": 1}'
```

## Arquivo de Dados (pausas.csv)

O arquivo `pausas.csv` é criado automaticamente e contém as seguintes colunas:

- `id_funcionario`: ID numérico do funcionário
- `nome_funcionario`: Nome do funcionário
- `equipe`: Equipe (n1 ou n2)
- `inicio_pausa`: Data e hora de início da pausa (formato ISO)
- `fim_pausa`: Data e hora de fim da pausa (formato ISO)
- `duracao_segundos`: Duração da pausa em segundos

### Exemplo de dados no CSV:
```csv
id_funcionario,nome_funcionario,equipe,inicio_pausa,fim_pausa,duracao_segundos
1,Funcionário 1,n1,2025-09-10T10:05:39.123456,2025-09-10T10:07:02.654321,83
2,Funcionário 2,n1,2025-09-10T10:06:15.789012,2025-09-10T10:08:30.456789,135
```

## Personalização

### Alterar Nomes dos Funcionários

Edite o arquivo `app.py` e substitua as linhas de criação dos funcionários:

```python
# Substituir estas linhas:
for i in range(1, 9):
    gerenciador.adicionar_funcionario(Funcionario(i, f"Funcionário {i}", "n1"))
for i in range(9, 17):
    gerenciador.adicionar_funcionario(Funcionario(i, f"Funcionário {i}", "n2"))

# Por nomes personalizados:
gerenciador.adicionar_funcionario(Funcionario(1, "João Silva", "n1"))
gerenciador.adicionar_funcionario(Funcionario(2, "Maria Oliveira", "n1"))
# ... continue para todos os funcionários
```

### Alterar Configurações

Você pode ajustar os parâmetros no arquivo `app.py`:

```python
# Exemplo: limite de 3 pessoas por equipe e pausa de 15 minutos
gerenciador = GerenciadorPausas(limite_pausa_por_equipe=3, duracao_pausa_minutos=15)
```

## Recursos da Interface

- **Design Responsivo**: Funciona em desktop e dispositivos móveis
- **Cores Intuitivas**: Verde para disponível, vermelho para em pausa
- **Feedback Visual**: Mensagens de sucesso e erro
- **Atualização em Tempo Real**: Contador de tempo das pausas
- **Interface Limpa**: Design moderno e profissional
- **Navegação Simples**: Acesso fácil às métricas

## Tecnologias Utilizadas

- **Backend**: Python, Flask, Flask-CORS
- **Frontend**: HTML5, CSS3, JavaScript (Vanilla)
- **Persistência**: CSV (formato simples e universal)
- **Estilo**: CSS Grid, Flexbox, Gradientes
- **Comunicação**: Fetch API para chamadas AJAX

## Benefícios das Métricas

1. **Controle de Produtividade**: Monitore o tempo total de pausas
2. **Identificação de Padrões**: Veja quais funcionários fazem mais pausas
3. **Comparação entre Equipes**: Analise diferenças entre N1 e N2
4. **Relatórios Mensais**: Use os dados do CSV para relatórios gerenciais
5. **Otimização**: Identifique oportunidades de melhoria nos processos

## Suporte

Para dúvidas ou problemas, consulte a documentação ou entre em contato com o administrador do sistema. O arquivo `pausas.csv` pode ser aberto em qualquer planilha (Excel, Google Sheets) para análises adicionais.
