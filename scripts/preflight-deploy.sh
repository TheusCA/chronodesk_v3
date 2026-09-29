#!/bin/sh
# ============================================================================
# Pre-checagem de deploy do ChronoDesk / Portal SDK
#
# Espelha as travas de seguranca de config.php (linhas 123-145) e o
# pre-requisito de autenticacao de auth_ldap.php (AD_DOMAIN / AD_SERVERS).
# Se uma trava dispararia, config.php lanca RuntimeException e a aplicacao
# inteira retorna 500. Rode ANTES do git pull.
#
# Uso:  sh scripts/preflight-deploy.sh [caminho-do-.env]
#       (padrao: /var/www/.env)
#
# Saida: apenas OK / FALHA por item. NUNCA imprime valores do .env.
# Codigo de saida: 0 = pode seguir, 1 = abortar.
#
# Fidelidade: validado contra um oraculo em PHP que replica load_env() e as
# travas de config.php, em 12 fixtures — incluindo valores entre aspas,
# comentarios, valor contendo "=", APP_DEBUG em maiusculas, chaves ausentes,
# chaves so com espacos e CHAVE DUPLICADA (load_env usa a PRIMEIRA ocorrencia,
# porque so grava quando getenv() retorna false).
#
# Limitacao conhecida: variaveis ja exportadas no ambiente do PHP-FPM (por
# exemplo via env[...] em www.conf) tem precedencia sobre o .env e nao sao
# vistas por esta checagem.
# ============================================================================

ENVF="${1:-/var/www/.env}"

if [ ! -r "$ENVF" ]; then
    echo "ARQUIVO .env: FALHA (nao encontrado ou sem permissao de leitura)"
    echo "RESULTADO=1"
    exit 1
fi

# Le uma chave do .env replicando load_env(): ignora comentarios e linhas sem
# "=", corta espacos da chave e do valor, remove aspas das pontas e mantem a
# PRIMEIRA ocorrencia. \042 = aspas duplas, \047 = aspas simples.
gv() {
    awk -v k="$1" '
        /^[[:space:]]*#/ { next }
        index($0, "=") == 0 { next }
        {
            k2 = substr($0, 1, index($0, "=") - 1)
            v2 = substr($0, index($0, "=") + 1)
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", k2)
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", v2)
            gsub(/^[\042\047]+|[\042\047]+$/, "", v2)
            if (k2 == k && !seen) { v = v2; seen = 1 }
        }
        END { print v }
    ' "$ENVF"
}

F=0

# Pre-requisito de autenticacao: sem estes, autenticar_ad() falha fechada.
for v in AD_DOMAIN AD_SERVERS; do
    if [ -n "$(gv $v)" ]; then echo "$v: OK"; else echo "$v: FALHA"; F=1; fi
done

# Travas de config.php. Só valem fora de development (APP_ENV ?: 'development').
APP_ENV_VALUE=$(gv APP_ENV)
[ -n "$APP_ENV_VALUE" ] || APP_ENV_VALUE=development

if [ "$APP_ENV_VALUE" = development ]; then
    echo "APP_ENV=development: travas de config.php NAO se aplicam"
else
    # APP_DEBUG === 'true' — comparacao sensivel a maiusculas, como no PHP.
    if [ "$(gv APP_DEBUG)" = true ]; then echo "APP_DEBUG: FALHA"; F=1; else echo "APP_DEBUG: OK"; fi

    DB_USER_VALUE=$(gv DB_USER)
    [ -n "$DB_USER_VALUE" ] || DB_USER_VALUE=chronodesk_app
    if [ "$DB_USER_VALUE" = root ]; then echo "DB_USER: FALHA"; F=1; else echo "DB_USER: OK"; fi

    # Placeholder: vazio OU contendo troque/changeme/change_me (case-insensitive).
    DB_PASS_VALUE=$(gv DB_PASS)
    if [ -z "$DB_PASS_VALUE" ] || printf %s "$DB_PASS_VALUE" | grep -qiE 'troque|changeme|change_me'; then
        echo "DB_PASS: FALHA"; F=1
    else
        echo "DB_PASS: OK"
    fi

    if [ -n "$(gv AD_ADMIN_USERS)" ]; then echo "AD_ADMIN_USERS: OK"; else echo "AD_ADMIN_USERS: FALHA"; F=1; fi
fi

# FORCE_HTTPS ligado sem vhost HTTPS proprio derruba o portal (301 em loop).
case $(printf %s "$(gv FORCE_HTTPS)" | tr 'A-Z' 'a-z') in
    1|true|yes|on) echo "FORCE_HTTPS: FALHA"; F=1 ;;
    *) echo "FORCE_HTTPS: OK" ;;
esac

# Informativo: sem mod_headers os cabecalhos de cache sao ignorados em silencio.
if apache2ctl -M 2>/dev/null | grep -q headers_module; then
    echo "mod_headers: ATIVO (informativo)"
else
    echo "mod_headers: INATIVO (informativo, nao aborta; cache nao sera aplicado)"
fi

echo "RESULTADO=$F"
exit $F
