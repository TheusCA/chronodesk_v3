# PROMPT COMPLETO PARA O CHATGPT — Portal SDK v3.0
## Contexto, Levantamento de Requisitos, Alterações Realizadas e Próximos Passos

---

## 1. CONTEXTO DO PROJETO

Este projeto se chama **Portal SDK**. É um sistema web de **controle de pausas de colaboradores** desenvolvido em **PHP + MySQL + HTML/CSS/JS puro**, sem frameworks. Ele foi originalmente desenvolvido por um colaborador que saiu da empresa e ficou rodando localmente em um **XAMPP no Windows** (máquina física interna).

O novo responsável pelo projeto (você, leitor deste prompt) ficou encarregado de:
1. Entender o projeto
2. Aplicar melhorias e corrigir problemas identificados
3. Subir o projeto em uma **VM Linux** (Ubuntu/Debian) com Apache + PHP + MySQL
4. Deixar o sistema acessível a todos os colaboradores da empresa via rede interna

A empresa usa **Active Directory (AD)** com domínio `gruponp.local`. Os servidores AD disponíveis na rede são:
- `servidor-ad-1.exemplo.local`
- `servidor-ad-2.exemplo.local`
- `servidor-ad-3.exemplo.local`

O DNS interno deve ser configurado conforme a rede da empresa.

---

## 2. O QUE O SISTEMA FAZ (LEVANTAMENTO DE REQUISITOS COMPLETO)

### 2.1 Visão Geral
O Portal SDK é um painel de controle de pausas para um time de suporte técnico dividido em duas equipes: **N1** e **N2**. Cada equipe tem entre 7 e 9 colaboradores (chamados de CIs — Colaboradores Internos).

### 2.2 Fluxo Operacional
1. **Tela principal (index.php):** Exibe os cards de todos os CIs de N1 e N2, mostrando em tempo real quem está em pausa, há quanto tempo, e a situação de disponibilidade (dentro/fora da jornada, almoço, disponível).
2. **Iniciar Pausa:** O CI (ou um gestor) seleciona o CI na lista e o motivo da pausa (Café, Pessoal ou Reunião) e clica em "Iniciar".
3. **Finalizar Pausa:** Da mesma forma, seleciona o CI e clica em "Finalizar".
4. **Limite de pausas simultâneas:** Configurável. Por padrão, no máximo 2 CIs de uma mesma equipe podem estar em pausa ao mesmo tempo.
5. **Alertas de tempo:** Se um CI estiver em pausa há mais de 15 minutos → alerta laranja. Mais de 20 minutos → alerta vermelho crítico com banner fixo piscante no topo da tela.
6. **Pausas de Reunião:** Têm fluxo especial — são "solicitadas" e ficam com status "pendente" até que um administrador aprove ou rejeite via painel admin. Não contam no limite de pausas da equipe enquanto pendentes.
7. **Registro de histórico:** Toda pausa finalizada é registrada no banco MySQL com duração, motivo, alertas disparados e status.
8. **Painel Admin (admin.php):** Acessível por login. Permite configurar limites, duração máxima, alertas, cadastrar/editar/remover CIs, aprovar pausas de reunião, e baixar relatório CSV.
9. **Tela de Métricas (metricas.php):** Acessível por login de gestor. Mostra gráficos e tabelas de pausas por funcionário, equipe, motivo, alertas disparados, pausas de reunião aprovadas/rejeitadas/pendentes.
10. **Auto-refresh:** A tela principal atualiza o status automaticamente a cada 30 segundos.

### 2.3 Estrutura de Dados Principal

**Funcionários (CIs) — atualmente em JSON, sendo migrado para MySQL:**
```
id, nome, equipe (n1/n2), jornada_entrada, jornada_saida, almoco_inicio, almoco_fim, ativo, ad_login (NOVO)
```

**Pausas (MySQL, tabela `pausas`):**
```
id, id_funcionario, nome_funcionario, equipe, inicio_pausa, fim_pausa, duracao_segundos,
motivo_pausa, alerta_15min, alerta_20min, status_aprovacao, observacao_reuniao, data_registro
```

**Estado atual (estado.json — arquivo temporário em memória):**
Guarda o estado em tempo real de quem está ou não em pausa. É atualizado a cada início/finalização de pausa.

### 2.4 Fluxo de Autenticação (Admin/Gestor)
- Login separado via `admin_login.php` e `login.php`
- Sessão PHP com timeout absoluto (8h para gestor, 4h para admin) e timeout de inatividade (30min gestor, 20min admin)
- Proteção CSRF em todos os endpoints de escrita
- Rate limiting por IP para tentativas de login

### 2.5 Segurança Implementada
- Proteção CSRF (token por sessão)
- Validação rigorosa de inputs (IDs, motivos, nomes)
- Prepared statements PDO em todos os queries
- Headers de segurança (CSP, HSTS, X-Frame-Options)
- Sistema de auditoria (tabela `audit_log` no MySQL)
- Senhas com bcrypt (custo 12)
- LOCK_EX em escritas de arquivo para evitar race conditions

---

## 3. ESTRUTURA DE ARQUIVOS DO PROJETO

```
ChronoDesk_v3.0/
├── index.php                    ← Tela principal (cards de CIs + ações de pausa)
├── admin.php                    ← Painel administrativo
├── admin_login.php              ← Login do admin
├── admin_logout.php             ← Logout do admin
├── login.php                    ← Login do gestor (métricas)
├── logout.php                   ← Logout do gestor
├── metricas.php                 ← Dashboard de métricas
├── config.php                   ← Configurações centrais, funções utilitárias
├── config_assets.php            ← Configuração de assets (CSS/JS com cache busting)
├── db.php                       ← Conexão PDO com MySQL
├── init.php                     ← Inicialização do sistema (carrega funcionários e estado)
├── security.php                 ← Módulo de segurança (CSRF, rate limit, sanitização, auditoria)
├── auth_ldap.php                ← [NOVO v3.0] Autenticação LDAP/Active Directory
├── .env.example                 ← Template de variáveis de ambiente
├── .htaccess                    ← Proteção de arquivos sensíveis
├── database_SECURED.sql         ← Schema MySQL completo (com tabela funcionarios NOVA)
├── funcionarios.json            ← Fonte de dados dos CIs (fallback enquanto não migra pro MySQL)
├── estado.json                  ← Estado em tempo real das pausas (gerado automaticamente)
├── pausas.csv                   ← Backup CSV das pausas (fallback do MySQL)
├── config_sistema.json          ← Configurações do sistema (limite pausas, duração, etc.)
├── api/
│   ├── status.php               ← GET: retorna status atual de todos os CIs
│   ├── iniciar_pausa.php        ← POST: inicia pausa de um CI
│   ├── finalizar_pausa.php      ← POST: finaliza pausa de um CI
│   ├── solicitar_pausa_com_aprovacao.php  ← POST: solicita pausa de reunião
│   ├── aprovar_pausa.php        ← POST: admin aprova pausa de reunião
│   ├── rejeitar_pausa.php       ← POST: admin rejeita pausa de reunião
│   ├── solicitacoes_pendentes.php ← GET: lista pausas de reunião pendentes
│   ├── listar_funcionarios.php  ← [NOVO v3.0] GET: lista CIs para os selects
│   ├── adicionar_funcionario.php← POST: admin adiciona CI
│   ├── atualizar_funcionario.php← POST: admin edita CI
│   ├── remover_funcionario.php  ← POST: admin remove CI
│   ├── metricas.php             ← GET: dados para dashboard de métricas
│   ├── salvar_configuracao.php  ← POST: salva configurações do sistema
│   ├── download_relatorio.php   ← GET: download CSV do relatório
│   ├── alterar_senha_admin.php  ← POST: altera senha do admin
│   └── usuarios.php             ← GET/POST: gerenciamento de usuários gestores
├── classes/
│   ├── Funcionario.php          ← Classe CI com horários e disponibilidade
│   ├── GerenciadorPausas.php    ← Lógica central de pausas, limites e métricas
│   └── Usuario.php              ← Classe de usuários admin/gestores
└── static/
    ├── css/
    │   ├── style.css            ← Estilos da tela principal
    │   ├── admin.css            ← Estilos do painel admin
    │   ├── login.css            ← Estilos das telas de login
    │   └── metricas.css         ← Estilos do dashboard de métricas
    └── js/
        ├── script.js            ← [ATUALIZADO v3.0] JS principal (selects, banner, fix duplo setInterval)
        ├── admin.js             ← JS do painel admin
        ├── admin_users.js       ← JS de gerenciamento de usuários
        ├── metricas.js          ← JS do dashboard de métricas
        ├── csrf_fetch.js        ← Interceptor fetch com CSRF token automático
        └── theme.js             ← Alternância de tema claro/escuro
```

---

## 4. ALTERAÇÕES JÁ REALIZADAS NA v3.0

### 4.1 ✅ Input de ID substituído por Select com nomes dos CIs
**Problema:** A tela principal tinha dois campos `<input type="number">` onde o usuário digitava o ID numérico do CI (1–999). Qualquer pessoa podia digitar qualquer número, incluindo IDs que não existiam ou IDs de outros CIs.

**Solução aplicada:**
- Criado endpoint `api/listar_funcionarios.php` que retorna a lista de CIs ordenada por nome
- Os dois inputs de ID foram substituídos por `<select>` com os nomes de todos os CIs ativos
- O JS carrega os selects automaticamente ao iniciar a página via `carregarFuncionariosSelect()`
- Adicionada confirmação com o nome do CI antes de iniciar ou finalizar pausa

**Arquivos alterados:** `index.php`, `static/js/script.js`, `api/listar_funcionarios.php` (novo)

### 4.2 ✅ Correção do double setInterval
**Problema:** Havia dois `setInterval(atualizarStatus, 30000)` sendo criados — um fora do DOMContentLoaded (executa imediatamente) e outro dentro do DOMContentLoaded (executa ao carregar o DOM). Resultado: a API `/status.php` era chamada duas vezes a cada 30 segundos desnecessariamente.

**Solução:** Removido o setInterval do escopo global. Mantido apenas o setInterval dentro do `DOMContentLoaded`.

**Arquivo alterado:** `static/js/script.js`

### 4.3 ✅ Banner fixo para alertas críticos
**Problema:** Alertas críticos (pausa > 20 min) apareciam apenas na área de mensagens da página, que sumia após 5 segundos. O gestor podia perder o alerta.

**Solução:** Criado banner fixo vermelho piscante no topo da tela que só some quando o gestor clicar no X ou quando a pausa crítica for finalizada.

**Arquivo alterado:** `static/js/script.js`, `index.php`

### 4.4 ✅ Limpeza do funcionarios.json
**Problema:** Havia um funcionário de teste `"nome": "asdasd"` com ID 18 no arquivo de produção. Todos os funcionários estavam com jornada `00:00–23:59` (sem horário real definido).

**Solução:** Removido o funcionário de teste. Corrigidos os horários para `08:00–17:00` com almoço `12:00–13:00`.

**Arquivo alterado:** `funcionarios.json`

### 4.5 ✅ Banco de dados atualizado — tabela funcionarios
**Problema:** Os funcionários viviam apenas no `funcionarios.json`, mas o histórico de pausas ficava no MySQL com `id_funcionario` sem nenhuma foreign key. Não havia integridade referencial.

**Solução:** Adicionada tabela `funcionarios` ao `database_SECURED.sql` com campo `ad_login` para futura autenticação via AD. Incluídos os INSERTs iniciais com todos os CIs cadastrados. Adicionada FOREIGN KEY em `pausas(id_funcionario)`.

**Arquivo alterado:** `database_SECURED.sql`

### 4.6 ✅ Módulo de autenticação LDAP/AD criado
**Arquivo criado:** `auth_ldap.php`

**O que ele faz:**
- Conecta ao Active Directory via LDAP na porta 389 (ou 636/LDAPS)
- Suporte a failover automático entre os 3 servidores AD
- Autentica o CI com login e senha do Windows
- Busca atributos do usuário no AD (displayName, email, departamento, grupos)
- Sanitização de username contra LDAP Injection
- Associa o `ad_login` ao funcionário cadastrado na tabela `funcionarios`

**Status atual:** O módulo está criado e funcional, mas **ainda não integrado** ao fluxo de iniciar/finalizar pausa. Esta é a principal tarefa pendente descrita na Seção 5.

### 4.7 ✅ .env.example atualizado
Adicionadas as variáveis `AD_DOMAIN`, `AD_SERVERS`, `AD_PORT`, `AD_USE_TLS` com valores da rede da empresa.

---

## 5. PRÓXIMOS PASSOS — O QUE O CHATGPT DEVE IMPLEMENTAR

### 5.1 🔴 CRÍTICO: Integrar autenticação AD ao fluxo de pausa

**Descrição do que fazer:**

Atualmente, ao selecionar um CI no `<select>` e clicar em "Iniciar Pausa", o sistema envia diretamente o `id` do CI para a API sem nenhuma verificação de identidade. Qualquer um pode iniciar/finalizar pausa de qualquer CI.

**O fluxo desejado é:**

1. Ao clicar em "Iniciar Pausa" ou "Finalizar Pausa", abrir um **modal** (não um `alert()` nativo) solicitando:
   - Campo: **Login do Windows** (ex: `joao.silva`)
   - Campo: **Senha do Windows**
   - Botão: Confirmar
2. O frontend envia login + senha + id_funcionario + motivo para um novo endpoint PHP
3. O endpoint PHP chama `autenticar_ci_via_ad($login, $senha)` do `auth_ldap.php`
4. Se a autenticação falhar → retorna erro
5. Se a autenticação passar → verifica se o `ad_login` retornado bate com o CI selecionado (opcional mas recomendado para evitar CI pausar outro CI)
6. Se tudo OK → inicia/finaliza a pausa normalmente

**Arquivos a criar/modificar:**
- `api/iniciar_pausa_ad.php` (novo endpoint que inclui autenticação LDAP)
- `api/finalizar_pausa_ad.php` (novo endpoint que inclui autenticação LDAP)
- `index.php` — adicionar o modal de autenticação
- `static/js/script.js` — ajustar funções `iniciarPausa()` e `finalizarPausa()` para abrir o modal antes de enviar
- `auth_ldap.php` — já existe, apenas importar com `require_once`

**Observação importante:** O `auth_ldap.php` requer a extensão PHP LDAP instalada no servidor. Para instalar no Ubuntu/Debian: `sudo apt install php-ldap && sudo phpenmod ldap && sudo systemctl restart apache2`

### 5.2 🟡 IMPORTANTE: Migrar funcionarios.json para MySQL

**Descrição:** Atualmente o código carrega os funcionários do `funcionarios.json` via `carregar_funcionarios_sistema()` em `config.php`. O banco MySQL já tem a tabela `funcionarios` criada no `database_SECURED.sql`. 

**O que fazer:**
1. Criar função `carregar_funcionarios_mysql()` em `config.php` que carrega da tabela MySQL ao invés do JSON
2. Atualizar a lógica em `init.php` para usar MySQL como fonte primária e JSON apenas como fallback de emergência
3. Atualizar `api/adicionar_funcionario.php`, `api/atualizar_funcionario.php`, `api/remover_funcionario.php` para operar na tabela MySQL
4. Atualizar o painel admin para mostrar e editar o campo `ad_login` de cada CI

### 5.3 🟡 IMPORTANTE: Campo `ad_login` no painel admin

No painel admin (`admin.php`), na seção de gerenciamento de funcionários, adicionar o campo `ad_login` (Login do Windows) no formulário de cadastro e edição de CIs. Este campo é o que vincula o CI ao Active Directory.

**Exemplo de interface:**
```
Nome: [David Alexandre        ]
Equipe: [N1 ▼]
Login AD: [david.alexandre     ] ← login do Windows, sem @domínio
Jornada: [08:00] às [17:00]
Almoço: [12:00] às [13:00]
[Salvar]
```

### 5.4 🟢 MELHORIA: Contador de pausas do dia nos cards

**O que fazer:**
- Na API `status.php`, consultar a tabela `pausas` do MySQL para contar quantas pausas cada CI fez hoje
- Incluir o campo `pausas_hoje` no retorno JSON de cada funcionário
- O `script.js` já tem suporte a esse campo no `criarCardFuncionario()` — basta a API retornar o dado

### 5.5 🟢 MELHORIA: Validação mínima de nome no frontend

No painel admin, ao cadastrar um funcionário, validar no JavaScript que:
- Nome tem pelo menos 3 caracteres
- Nome não contém apenas números
- Login AD tem pelo menos 4 caracteres e não contém espaços

---

## 6. INFORMAÇÕES DA VM LINUX PARA DEPLOY

### 6.1 Stack necessária
- **OS:** Ubuntu 20.04 LTS ou mais recente (ou Debian 11+)
- **Web Server:** Apache 2.4 com mod_rewrite habilitado
- **PHP:** 8.0 ou superior com extensões:
  - `php-mysql` (conexão PDO MySQL)
  - `php-ldap` (autenticação AD — OBRIGATÓRIO para SSO)
  - `php-json` (padrão, normalmente já incluso)
  - `php-mbstring` (para funções de string multibyte)
- **MySQL:** 8.0 ou MariaDB 10.6+

### 6.2 Passo a passo de instalação na VM (Ubuntu/Debian)

```bash
# 1. Atualizar sistema
sudo apt update && sudo apt upgrade -y

# 2. Instalar Apache
sudo apt install apache2 -y
sudo a2enmod rewrite
sudo systemctl enable apache2

# 3. Instalar PHP e extensões
sudo apt install php php-mysql php-ldap php-mbstring php-json -y

# 4. Instalar MySQL
sudo apt install mysql-server -y
sudo mysql_secure_installation

# 5. Criar banco e usuário
sudo mysql -u root -p << 'SQL'
CREATE DATABASE sistema_pausas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'chronodesk_app'@'localhost' IDENTIFIED BY 'SENHA_FORTE_AQUI';
GRANT SELECT, INSERT, UPDATE, DELETE ON sistema_pausas.* TO 'chronodesk_app'@'localhost';
FLUSH PRIVILEGES;
SQL

# 6. Importar schema
sudo mysql -u root -p sistema_pausas < /var/www/html/chronodesk/database_SECURED.sql

# 7. Copiar projeto para pasta do Apache
sudo cp -r /caminho/do/projeto/* /var/www/html/chronodesk/

# 8. Permissões
sudo chown -R www-data:www-data /var/www/html/chronodesk/
sudo chmod -R 755 /var/www/html/chronodesk/
sudo chmod 664 /var/www/html/chronodesk/estado.json
sudo chmod 664 /var/www/html/chronodesk/pausas.csv
sudo chmod 664 /var/www/html/chronodesk/funcionarios.json
sudo chmod 664 /var/www/html/chronodesk/config_sistema.json

# 9. Configurar .env (fora do document root é mais seguro)
sudo cp /var/www/html/chronodesk/.env.example /var/www/.env
sudo nano /var/www/.env
# Preencher DB_HOST, DB_NAME, DB_USER, DB_PASS, SECRET_KEY, ALLOWED_ORIGINS, AD_*

# 10. Testar extensão LDAP
php -m | grep ldap
# Se não aparecer: sudo apt install php-ldap && sudo phpenmod ldap && sudo systemctl restart apache2

# 11. Verificar conectividade com o AD
nc -zv servidor-ad-1.exemplo.local 389
# Deve retornar uma conexão LDAP bem-sucedida.

# 12. Configurar VirtualHost do Apache (opcional, mas recomendado)
sudo nano /etc/apache2/sites-available/chronodesk.conf
```

**Conteúdo do VirtualHost:**
```apache
<VirtualHost *:80>
    ServerName chronodesk.gruponp.local
    DocumentRoot /var/www/html/chronodesk
    
    <Directory /var/www/html/chronodesk>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/chronodesk_error.log
    CustomLog ${APACHE_LOG_DIR}/chronodesk_access.log combined
</VirtualHost>
```

```bash
sudo a2ensite chronodesk.conf
sudo systemctl reload apache2
```

### 6.3 Verificações pós-deploy
1. Acessar `http://IP_DA_VM/chronodesk/` — deve mostrar a tela principal com os cards dos CIs
2. Verificar que os selects de CI estão preenchidos
3. Acessar `http://IP_DA_VM/chronodesk/admin_login.php` — deve mostrar tela de login
4. Verificar que o MySQL está recebendo pausas: `SELECT * FROM pausas ORDER BY id DESC LIMIT 5;`
5. Testar conectividade LDAP: `ldapsearch -H ldap://servidor-ad-1.exemplo.local -x -b "DC=exemplo,DC=local" -D "usuario@exemplo.local" -W`

---

## 7. ARQUIVOS COM MAIOR ATENÇÃO NECESSÁRIA

| Arquivo | Por quê |
|---|---|
| `auth_ldap.php` | Módulo criado mas ainda não integrado ao fluxo de pausa — principal pendência |
| `api/iniciar_pausa.php` | Precisará incluir `auth_ldap.php` e validar credenciais antes de pausar |
| `api/finalizar_pausa.php` | Idem |
| `config.php` | Função `carregar_funcionarios_sistema()` precisa ser atualizada para usar MySQL |
| `database_SECURED.sql` | Já atualizado com tabela `funcionarios` e dados iniciais |
| `admin.php` | Precisa do campo `ad_login` nos formulários de cadastro/edição |
| `.env.example` | Já atualizado com variáveis AD — copiar para `.env` e preencher em produção |

---

## 8. CONVENÇÕES DO PROJETO (para o ChatGPT não quebrar o padrão)

- **Backend:** PHP 8.0+, sem frameworks, PDO para MySQL
- **Autenticação:** Sessões PHP com `verificar_login()` e `verificar_admin_login()` de `config.php`
- **CSRF:** Toda chamada POST usa o token gerado por `generate_csrf_token()` e validado por `require_csrf_token()` em `security.php`. O JS usa `csrf_fetch.js` que injeta o token automaticamente em todo `fetch()`
- **Retorno das APIs:** Sempre JSON com `json_response(['sucesso' => bool, 'mensagem' => string])` de `config.php`
- **Validações:** Usar funções de `security.php`: `validate_funcionario_id()`, `validate_nome()`, `validate_motivo_pausa()`, `sanitize_input()`
- **Inclusão de dependências:** Sempre via `require_once __DIR__ . '/caminho/arquivo.php'` com path relativo ao arquivo atual
- **Timezone:** `America/Sao_Paulo` definido em `config.php`
- **Tema:** Dark/Light mode via `theme.js` com variáveis CSS em `:root`
- **Sem frameworks JS:** JavaScript puro (Vanilla JS), sem jQuery, React ou Vue

---

## 9. VERSÃO DO PROJETO

- **v2.1.0:** Versão original com patches de segurança aplicados
- **v3.0 (esta versão):** Selects de CI, fix do double setInterval, banner crítico, limpeza do JSON, banco de dados com tabela funcionarios, módulo auth_ldap.php criado

---

*Documento gerado automaticamente para transferência de contexto entre sessões de IA.*
*Data de geração: Maio 2026*
