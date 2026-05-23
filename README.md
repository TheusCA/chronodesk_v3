# Sistema de Gerenciamento de Pausas - Versão PHP/XAMPP

Esta é a versão migrada do sistema de gerenciamento de pausas, agora utilizando **PHP** com **XAMPP**.

## 📋 Requisitos

- **XAMPP** instalado (versão 7.4 ou superior recomendada)
- **PHP 7.4+** (incluído no XAMPP)
- **Apache** (incluído no XAMPP)
- Extensão **ZipArchive** do PHP (geralmente já habilitada)

## 🚀 Instalação

### 1. Copiar arquivos para o XAMPP

1. Copie toda a pasta do projeto para o diretório `htdocs` do XAMPP:
   - **Windows**: `C:\xampp\htdocs\Sistema_Pausas`
   - **Linux**: `/opt/lampp/htdocs/Sistema_Pausas`
   - **macOS**: `/Applications/XAMPP/htdocs/Sistema_Pausas`

### 2. Configurar permissões (Linux/macOS)

```bash
chmod -R 755 /opt/lampp/htdocs/Sistema_Pausas
chmod 666 /opt/lampp/htdocs/Sistema_Pausas/pausas.csv
```

### 3. Iniciar serviços do XAMPP

1. Abra o **Painel de Controle do XAMPP**
2. Inicie o **Apache**
3. (Opcional) Inicie o **MySQL** se precisar de banco de dados no futuro

### 4. Acessar a aplicação

Abra seu navegador e acesse:
```
http://localhost/Sistema_Pausas/
```

## 📁 Estrutura do Projeto

```
Sistema_Pausas/
├── api/                          # Endpoints da API REST
│   ├── status.php
│   ├── iniciar_pausa.php
│   ├── finalizar_pausa.php
│   ├── metricas.php
│   ├── solicitar_pausa_com_aprovacao.php
│   ├── aprovar_pausa.php
│   ├── rejeitar_pausa.php
│   ├── solicitacoes_pendentes.php
│   └── download_relatorio.php
├── classes/                      # Classes PHP
│   ├── Funcionario.php
│   └── GerenciadorPausas.php
├── static/                       # Arquivos estáticos
│   ├── css/
│   │   └── style.css
│   └── js/
│       ├── script.js
│       └── metricas.js
├── config.php                    # Configurações do sistema
├── init.php                      # Inicialização (funcionários, etc)
├── index.php                     # Página principal
├── login.php                     # Página de login
├── metricas.php                  # Página de métricas
├── logout.php                    # Logout
├── .htaccess                     # Configurações Apache
├── pausas.csv                    # Arquivo de dados (criado automaticamente)
└── README.md                     # Este arquivo
```

## 🔧 Configuração

### Variáveis de ambiente

Copie `.env.example` para `.env` fora do document root, quando possível, e ajuste os valores do ambiente. Nunca versione o `.env` real.

```env
SECRET_KEY=GERE_UMA_CHAVE_FORTE_AQUI
DB_HOST=localhost
DB_NAME=sistema_pausas
DB_USER=chronodesk_user
DB_PASS=troque_esta_senha
```

### Login administrativo

O painel administrativo aceita autenticação híbrida:

- Login local existente, mantido como contingência por padrão.
- Login via AD/LDAP para usuários autorizados em `AD_ADMIN_USERS`.

Configuração:

```env
AD_ADMIN_USERS=mmdcamargo,dhrmendes,rgluciano
ENABLE_LOCAL_ADMIN=true
```

`AD_ADMIN_USERS` deve conter sAMAccountNames separados por vírgula. Se a variável não for configurada, o sistema usa `mmdcamargo,dhrmendes,rgluciano` como fallback inicial. O login AD aceita `usuario` ou `usuario@paschoalotto.com.br`, normaliza para sAMAccountName em minúsculo e não salva senha AD.

Para bloquear o login local administrativo e permitir apenas AD/LDAP autorizado:

```env
ENABLE_LOCAL_ADMIN=false
```

### Alterar funcionários

Edite o arquivo `init.php` para adicionar, remover ou modificar funcionários.

### Alterar limites de pausa

Edite o arquivo `init.php`:

```php
$gerenciador = new GerenciadorPausas(
    limite_pausa_por_equipe: 2,      // Máximo de pausas simultâneas por equipe
    duracao_pausa_minutos: 20        // Duração limite da pausa
);
```

## 🌐 Endpoints da API

Todos os endpoints retornam JSON:

- `GET /api/status.php` - Status de todos os funcionários
- `POST /api/iniciar_pausa.php` - Iniciar pausa
- `POST /api/finalizar_pausa.php` - Finalizar pausa
- `GET /api/metricas.php` - Obter métricas (requer login)
- `POST /api/solicitar_pausa_com_aprovacao.php` - Solicitar pausa de reunião
- `POST /api/aprovar_pausa.php` - Aprovar pausa pendente
- `POST /api/rejeitar_pausa.php` - Rejeitar pausa pendente
- `GET /api/solicitacoes_pendentes.php` - Listar solicitações pendentes
- `GET /api/download_relatorio.php` - Download de relatório (requer login)

## 🔐 Segurança

### Autenticação

- O sistema usa autenticação segura com hash de senhas
- Configure uma senha forte via interface administrativa após primeira instalação
- Usuário padrão: `administrador`

⚠️ **IMPORTANTE**: Configure uma senha forte após primeira instalação via interface admin!

### Recomendações

1. Configure segredos e credenciais no `.env`, não em `config.php`
2. Use HTTPS em produção
3. Configure firewall adequadamente
4. Mantenha o XAMPP atualizado
5. Não exponha o XAMPP diretamente à internet sem proteção adequada

### Hardening de Produção

Para deploy em VM Linux corporativa:

1. Use `APP_ENV=production` e `APP_DEBUG=false`.
2. Configure `SECRET_KEY` forte no `.env`.
3. Use usuário MySQL dedicado, nunca `root`:
   ```sql
   CREATE USER 'chronodesk_user'@'localhost' IDENTIFIED BY 'troque_esta_senha';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX
     ON sistema_pausas.* TO 'chronodesk_user'@'localhost';
   FLUSH PRIVILEGES;
   ```
4. Instale/habilite extensões PHP: `php-mysql`, `php-ldap` e `php-zip`.
5. Configure LDAP/AD no `.env`: `AD_DOMAIN`, `AD_UPN_SUFFIX`, `AD_SERVERS`, `AD_PORT`, `AD_USE_TLS`, `AD_ADMIN_USERS`.
6. Use HTTPS no VirtualHost e defina `SESSION_COOKIE_SECURE=true`.
7. Mantenha `AllowOverride All` para o `.htaccess` bloquear arquivos sensíveis.
8. Garanta que `.env`, CSV, JSON, SQL, logs, dumps e ZIPs reais não sejam publicados nem versionados.

Headers ativos: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` e CSP. A CSP ainda permite `unsafe-inline` por compatibilidade com scripts/estilos inline existentes; remover isso fica como pendência de refatoração frontend.

O rate limit atual cobre login administrativo e autenticação AD nos fluxos de pausa via helper `check_rate_limit()` em `security.php`.

Não há funcionalidade de upload de arquivos. O download de relatório é gerado no servidor em diretório temporário, exige sessão autenticada e não aceita caminho de arquivo fornecido pelo usuário.

Antes de migrations em produção, faça backup do banco e dos arquivos locais de estado. Ordem recomendada:

1. Importar `database.sql`.
2. Configurar `.env`.
3. Rodar `php migrar_funcionarios_json_para_mysql.php`.
4. Testar login local de contingência.
5. Testar login AD administrativo.
6. Testar autenticação AD nos fluxos de pausa.

### Checklist Manual OWASP/WSTG

Valide no ambiente de homologação:

1. Acessar `/.env`, `/funcionarios.json`, `/database.sql`, `/.git/config` e confirmar HTTP 403/404.
2. Acessar `/migrar_funcionarios_json_para_mysql.php` e `/migrar_csv_para_mysql.php` pelo navegador e confirmar bloqueio.
3. Enviar POST sensível sem `X-CSRF-Token` e confirmar HTTP 403.
4. Enviar POST JSON com `Content-Type` incorreto e confirmar HTTP 415.
5. Tentar usar credenciais AD de um CI para pausar outro CI e confirmar bloqueio.
6. Tentar admin AD fora de `AD_ADMIN_USERS` e confirmar negação.
7. Definir `ENABLE_LOCAL_ADMIN=false` e confirmar bloqueio do admin local.
8. Confirmar que `ad_login` não aparece em `api/listar_funcionarios.php` sem sessão admin.
9. Confirmar que funcionário inativo não aparece na tela pública.
10. Confirmar que `APP_DEBUG=false` oculta detalhes técnicos.
11. Conferir headers: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` e CSP.
12. Confirmar que HTML/CSS/JS ficam visíveis ao navegador, mas PHP, `.env`, SQL, JSON real, CSV real, logs, backups, dumps e `.git` não ficam acessíveis por URL.

## 📊 Funcionalidades

- ✅ Gerenciamento de pausas por equipe
- ✅ Controle de limite de pausas simultâneas
- ✅ Sistema de aprovação para pausas de reunião
- ✅ Métricas detalhadas
- ✅ Relatórios em CSV/ZIP
- ✅ Alertas de tempo (15min e 20min)
- ✅ Interface responsiva
- ✅ API REST completa

## 🐛 Solução de Problemas

### Erro 500 (Internal Server Error)

1. Verifique os logs do Apache em:
   - Windows: `C:\xampp\apache\logs\error.log`
   - Linux: `/opt/lampp/logs/error_log`

2. Verifique se a extensão ZipArchive está habilitada:
   ```php
   <?php phpinfo(); ?>
   ```
   Procure por "zip" na saída.

### Arquivo CSV não é criado

1. Verifique permissões de escrita na pasta do projeto
2. Verifique se o PHP tem permissão para criar arquivos

### Página em branco

1. Ative a exibição de erros no `config.php` (apenas em desenvolvimento):
   ```php
   error_reporting(E_ALL);
   ini_set('display_errors', 1);
   ```

2. Verifique os logs do Apache

### Rotas não funcionam

1. Verifique se o módulo `mod_rewrite` está habilitado no Apache
2. Verifique se o arquivo `.htaccess` está presente
3. No `httpd.conf` do Apache, certifique-se de que:
   ```apache
   AllowOverride All
   ```

## 🔄 Migração do Sistema Python

Este sistema foi migrado do Python/Flask para PHP. As principais mudanças:

- **Backend**: Python/Flask → PHP puro
- **Templates**: Jinja2 → PHP nativo
- **API**: Mesma estrutura REST, agora em PHP
- **Persistência**: CSV (mantido)
- **Frontend**: JavaScript/CSS (mantido)

## 📝 Notas

- O arquivo `pausas.csv` é criado automaticamente na primeira execução
- Os dados são persistidos em CSV, compatível com o sistema anterior
- A interface visual permanece idêntica ao sistema Python
- Todos os recursos do sistema original foram mantidos

## 📞 Suporte

Para problemas ou dúvidas, verifique:
1. Logs do Apache
2. Logs do PHP (se configurado)
3. Console do navegador (F12) para erros JavaScript

## 📄 Licença

Este projeto mantém a mesma licença do sistema original.
