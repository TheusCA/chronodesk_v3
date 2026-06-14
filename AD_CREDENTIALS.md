# Credenciais AD e preparacao para cofre

## Estado atual

O login do ChronoDesk nao usa uma conta de servico armazenada no `.env`.
`auth_ldap.php` recebe o login e a senha digitados pelo usuario, faz o bind
LDAP diretamente com essa identidade e usa a mesma conexao autenticada para
consultar os atributos do usuario.

Variaveis usadas pelo fluxo atual:

- `AD_DOMAIN`
- `AD_UPN_SUFFIX`
- `AD_SERVERS`
- `AD_PORT`
- `AD_USE_TLS`
- `ENABLE_AD_AUTO_LINK`
- `AD_ADMIN_USERS`

Nao existem `AD_ADMIN_PASS`, senha AD hardcoded ou retorno de credencial para o
frontend. A senha fornecida no login nao e gravada em log.

## Provider preparado

`services/AdCredentialProvider.php` prepara credenciais de conta de servico para
uma integracao futura que realmente necessite desse tipo de bind. Ele nao esta
ligado ao login atual e nao executa Bash, Python ou comandos do sistema.

Modos suportados:

- `none`: padrao seguro; nenhuma credencial de servico e carregada.
- `env`: compatibilidade temporaria com `AD_BIND_USER` e `AD_BIND_PASS`.
- `runtime_file`: le `AD_BIND_USER_FILE` e `AD_BIND_PASS_FILE`.

O modo `runtime_file` exige caminhos absolutos, arquivos regulares fora do
projeto e do webroot, tamanho maximo de 4096 bytes e ausencia de permissao para
"outros" no Linux. Links simbolicos sao rejeitados.

## Integracao futura com cofre

O processo recomendado e o cofre ou um agente autenticado materializar os
segredos antes do Apache/PHP iniciar:

```text
/run/chronodesk/ad-bind-user
/run/chronodesk/ad-bind-pass
```

Configuracao sugerida:

```dotenv
AD_CREDENTIAL_PROVIDER=runtime_file
AD_BIND_USER_FILE=/run/chronodesk/ad-bind-user
AD_BIND_PASS_FILE=/run/chronodesk/ad-bind-pass
```

Os arquivos devem ser criados fora do webroot, em `tmpfs`, com proprietario e
grupo restritos ao processo que executa o PHP. Exemplo de permissao: `0640`,
sem acesso para outros usuarios. A unidade systemd responsavel pelo provisionamento
deve executar antes do Apache/PHP-FPM e remover os arquivos ao encerrar.

Ainda faltam, para uma integracao real:

- contrato e autenticacao da API/script do cofre;
- formato e ciclo de rotacao do segredo;
- identidade Linux que executa Apache/PHP;
- politica de renovacao e comportamento durante indisponibilidade do cofre;
- operacao AD concreta que usara a conta de servico.

Sem esses dados, o ChronoDesk nao chama o cofre e preserva o bind atual do
usuario final.
