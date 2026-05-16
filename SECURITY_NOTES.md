# Notas de Segurança - Sistema de Pausas

## 🔐 Autenticação

### Senhas
- ✅ **Nenhuma senha hardcoded no código**
- ✅ **Todas as senhas usam hash (password_hash/password_verify)**
- ✅ **Senhas armazenadas apenas como hash no config_sistema.json**

### Primeira Instalação
- O sistema gera um hash de senha padrão na primeira instalação
- **IMPORTANTE**: Use a interface administrativa para definir uma senha forte após primeira instalação
- O hash padrão é apenas para permitir primeiro acesso

### Alteração de Senha
- Use a interface administrativa (`/admin.php`) para alterar senhas
- Senhas devem ter no mínimo 8 caracteres
- Senhas são automaticamente convertidas para hash antes de salvar

## 🔒 Configurações de Segurança

### Headers HTTP
- ✅ X-Content-Type-Options: nosniff
- ✅ X-Frame-Options: DENY
- ✅ X-XSS-Protection: 1; mode=block
- ✅ Content-Security-Policy (básico)
- ✅ Referrer-Policy

### Sessões
- ✅ HttpOnly cookies
- ✅ Secure cookies (HTTPS em produção)
- ✅ SameSite=Strict
- ✅ Regeneração periódica de ID de sessão

### Rate Limiting
- ✅ Login admin: 5 tentativas / 15 minutos
- ✅ Login métricas: 5 tentativas / 15 minutos

## ⚠️ Configurações para Produção

### Obrigatório
1. **HTTPS**: Configure certificado SSL/TLS
2. **Senha forte**: Defina senha forte via interface admin
3. **SECRET_KEY**: Gere chave única e forte (32+ bytes aleatórios)
4. **CORS**: Restrinja origens permitidas (remover '*')

### Recomendado
5. **Backup seguro**: Configure backup regular do config_sistema.json
6. **Logs**: Configure logging de segurança
7. **Permissões**: Configure permissões de arquivo adequadas
8. **Firewall**: Configure firewall de aplicação
9. **Monitoramento**: Configure monitoramento de segurança

## 📝 Notas Importantes

- **NUNCA** commite arquivos com senhas em texto plano
- **NUNCA** exponha `config_sistema.json` publicamente
- **SEMPRE** use HTTPS em produção
- **SEMPRE** mantenha o sistema atualizado
- **REVISE** logs regularmente

## 🔄 Migração de Senhas

Se você tem uma instalação antiga com senhas em texto plano:
1. O sistema automaticamente converte para hash na primeira execução
2. A senha antiga é removida do arquivo de configuração
3. O hash é salvo automaticamente

## 🚨 Em Caso de Comprometimento

1. Altere todas as senhas imediatamente
2. Regenerar SECRET_KEY
3. Revogar todas as sessões ativas
4. Revisar logs de acesso
5. Verificar integridade dos arquivos
6. Notificar usuários se necessário
