# Guia de Instalação Automática

Este guia explica como usar os scripts de instalação e inicialização automática do sistema.

## 📋 Scripts Disponíveis

### Windows

1. **setup.bat** - Script de configuração (Batch)
2. **setup.ps1** - Script de configuração (PowerShell - mais completo)
3. **iniciar.bat** - Script de inicialização (Batch)
4. **iniciar.ps1** - Script de inicialização (PowerShell)

### Linux/macOS

1. **setup.sh** - Script de configuração
2. **iniciar.sh** - Script de inicialização

## 🚀 Instalação Rápida (Windows)

### Opção 1: Usando Batch (Mais Simples)

1. **Execute o script de configuração:**
   ```cmd
   setup.bat
   ```

2. **Copie todos os arquivos do projeto** para o diretório indicado pelo script (geralmente `C:\xampp\htdocs\Sistema_Pausas\`)

3. **Execute o script de inicialização:**
   ```cmd
   iniciar.bat
   ```

### Opção 2: Usando PowerShell (Recomendado)

1. **Abra o PowerShell como Administrador** (opcional, mas recomendado)

2. **Execute o script de configuração:**
   ```powershell
   .\setup.ps1
   ```
   
   Se aparecer erro de política de execução, execute primeiro:
   ```powershell
   Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser
   ```

3. **Copie todos os arquivos do projeto** para o diretório indicado

4. **Execute o script de inicialização:**
   ```powershell
   .\iniciar.ps1
   ```

## 🐧 Instalação Rápida (Linux/macOS)

1. **Torne os scripts executáveis:**
   ```bash
   chmod +x setup.sh iniciar.sh
   ```

2. **Execute o script de configuração:**
   ```bash
   ./setup.sh
   ```

3. **Copie todos os arquivos do projeto** para o diretório indicado (geralmente `/opt/lampp/htdocs/Sistema_Pausas/` ou `/Applications/XAMPP/htdocs/Sistema_Pausas/`)

4. **Execute o script de inicialização:**
   ```bash
   ./iniciar.sh
   ```
   
   **Nota:** Pode ser necessário executar com `sudo` dependendo das permissões do XAMPP.

## 📝 O que os Scripts Fazem

### Script de Configuração (setup.*)

- ✅ Verifica se o XAMPP está instalado
- ✅ Verifica se o PHP está disponível
- ✅ Verifica extensões necessárias (ZipArchive)
- ✅ Cria diretório de instalação se necessário
- ✅ Verifica permissões de escrita
- ✅ Lista arquivos necessários

### Script de Inicialização (iniciar.*)

- ✅ Verifica se o Apache já está rodando
- ✅ Inicia o Apache se necessário
- ✅ Verifica se o sistema está acessível
- ✅ Oferece abrir o navegador automaticamente
- ✅ Exibe informações de acesso e credenciais

## ⚙️ Configuração Personalizada

### Alterar Caminho do XAMPP

**Windows (Batch):**
Edite `setup.bat` e `iniciar.bat`, altere a linha:
```batch
set XAMPP_PATH=C:\xampp
```

**Windows (PowerShell):**
Edite `setup.ps1` e `iniciar.ps1`, altere a linha:
```powershell
$XAMPP_PATH = "C:\xampp"
```

**Linux/macOS:**
Edite `setup.sh` e `iniciar.sh`, altere a linha:
```bash
XAMPP_PATH="/opt/lampp"  # Linux
# ou
XAMPP_PATH="/Applications/XAMPP"  # macOS
```

## 🔧 Solução de Problemas

### Erro: "XAMPP não encontrado"

1. Verifique se o XAMPP está instalado
2. Ajuste o caminho no script conforme sua instalação
3. No Windows, caminhos comuns são:
   - `C:\xampp`
   - `C:\Program Files\xampp`
   - `D:\xampp`

### Erro: "Permissão negada" (Linux/macOS)

Execute com `sudo`:
```bash
sudo ./setup.sh
sudo ./iniciar.sh
```

### Erro: "Política de execução" (PowerShell)

Execute no PowerShell:
```powershell
Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser
```

### Apache não inicia

1. Verifique se a porta 80 está livre:
   ```bash
   # Linux/macOS
   lsof -i :80
   
   # Windows
   netstat -ano | findstr :80
   ```

2. Inicie manualmente pelo Painel de Controle do XAMPP

3. Verifique os logs do Apache:
   - Windows: `C:\xampp\apache\logs\error.log`
   - Linux: `/opt/lampp/logs/error_log`
   - macOS: `/Applications/XAMPP/xamppfiles/logs/error_log`

### Arquivos não encontrados

Certifique-se de copiar **todos** os arquivos do projeto para o diretório de instalação, incluindo:
- Todos os arquivos `.php`
- Pasta `classes/`
- Pasta `api/`
- Pasta `static/`
- Arquivo `.htaccess`

## 📍 Localização dos Arquivos

Após a instalação, os arquivos devem estar em:

**Windows:**
```
C:\xampp\htdocs\Sistema_Pausas\
```

**Linux:**
```
/opt/lampp/htdocs/Sistema_Pausas/
```

**macOS:**
```
/Applications/XAMPP/htdocs/Sistema_Pausas/
```

## 🌐 Acesso ao Sistema

Após a instalação e inicialização, acesse:

```
http://localhost/Sistema_Pausas/
```

**Autenticação:**
- O sistema usa autenticação segura com hash de senhas
- Configure uma senha forte via interface administrativa após primeira instalação
- Usuário padrão: `administrador`

⚠️ **IMPORTANTE:** Configure uma senha forte após primeira instalação via interface admin!

## ✅ Verificação Pós-Instalação

Para verificar se tudo está funcionando:

1. Acesse `http://localhost/Sistema_Pausas/`
2. Você deve ver a interface principal
3. Tente iniciar uma pausa de teste
4. Acesse as métricas (requer login)

## 🆘 Suporte

Se encontrar problemas:

1. Verifique os logs do Apache
2. Verifique se todas as extensões PHP estão habilitadas
3. Verifique permissões de arquivo
4. Consulte a documentação do XAMPP

## 📚 Próximos Passos

Após a instalação bem-sucedida:

1. ✅ Configure credenciais e segredos no `.env`
2. ✅ Configure funcionários em `init.php` (se necessário)
3. ✅ Teste todas as funcionalidades
4. ✅ Configure backup do arquivo `pausas.csv`
5. ✅ Configure HTTPS (recomendado para produção)

## Deploy em VM Linux Corporativa

Checklist recomendado para produção Apache/PHP/MySQL:

1. Instalar Apache, MySQL e PHP 7.4+.
2. Habilitar módulos/extensões: `mod_rewrite`, `php-mysql`, `php-ldap` e `php-zip`.
3. Criar o banco `sistema_pausas` com charset `utf8mb4`.
4. Criar usuário MySQL dedicado (`chronodesk_user`) e não usar `root`.
5. Importar `database.sql` e aplicar scripts de atualização necessários, como `update_users_table.sql`.
6. Copiar `.env.example` para `.env`, preferencialmente fora do document root, e ajustar `APP_ENV=production`, `APP_DEBUG=false`, DB e AD/LDAP.
7. Configurar permissões de escrita somente para arquivos de estado/log necessários pelo usuário do Apache.
8. Configurar VirtualHost com `DocumentRoot` no projeto e `AllowOverride All`.
9. Habilitar HTTPS e definir `SESSION_COOKIE_SECURE=true`.
10. Validar conectividade com os DCs em `AD_SERVERS` na porta `AD_PORT` e TLS quando `AD_USE_TLS=true`.
11. Rodar a migração de funcionários para MySQL e validar vínculos `ad_login`.
12. Conferir logs do Apache/PHP após o primeiro login e primeiro fluxo de pausa.

Pendências típicas do time de SO/Redes: certificado HTTPS, regras de firewall, DNS/VirtualHost, acesso de rede aos controladores AD e política de backup/rotação de logs.

