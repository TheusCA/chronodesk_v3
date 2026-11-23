#!/usr/bin/env bash
# ============================================================================
# Teste de fumaca do deploy 1 da Parte B (C3 da auditoria pre-deploy,
# docs/AUDITORIA_PRE_DEPLOY1.md; desenho 9.13.7). Roda na VM, pelo loopback
# (que passa pela manutencao do .htaccess), no passo 6f do roteiro: depois do
# build e da recarga (6e) e antes da reabertura (6g).
#
# Uso (roteiro, 6f):
#   env PHPLOG='<log de erros do PHP>' bash scripts/smoke-deploy1.sh '<ServerName do vhost>'
#   no shell root do `sudo -i` (o sudoers da VM tem `Defaults noexec`:
#   com `sudo` na frente, o php e o curl chamados pelo script nao rodariam).
#
# O que faz, nesta ordem:
#   1. login de admin pelo login_ci.php (login e senha do AD lidos no
#      terminal, a senha sem eco; nada em argumento de comando);
#   2. GET de api/status.php, api/portal/schedules.php, api/portal/pa_map.php,
#      api/portal/absences.php, api/portal/calendar.php e api/portal/reports.php
#      (os dois ultimos desde o deploy 5: o papel do dia do Critico, 8.8, vale
#      na Escala, no Calendario, nos relatorios e no Mapa PA), esperado 200;
#   3. uma gravacao reversivel: lanca uma folga de teste de dia inteiro no
#      proprio cadastro do admin, daqui a 30 dias, sem observacao, e a
#      cancela (o registro fica como cancelado, com autoria);
#   4. sai da sessao (logout_ci.php);
#   5. conta as linhas novas do log de erros do PHP, fora a linha de login
#      bem-sucedido que o auth_ldap.php sempre grava.
#
# So imprime codigos HTTP e contagens: nenhum corpo de resposta, nome, login,
# token ou cookie vai para a tela. Cookie e token ficam num diretorio do
# mktemp (700), apagado na saida. A ultima linha e "FUMACA OK" ou
# "PARE: ...". MEDIR_BASE troca o endereco base (so no teste local).
#
# Sem `set -e` de proposito: cada passo confere o proprio codigo e soma as
# falhas, e o `grep -c` sai com 1 quando conta zero.
# ============================================================================
set -Euo pipefail

HOST=${1:-}
LOG=${PHPLOG:-}
BASE=${MEDIR_BASE:-http://127.0.0.1}
if [ -z "$HOST" ] || [ -z "$LOG" ]; then
    echo "PARE: uso (shell root, sem sudo): env PHPLOG='<log de erros do PHP>' bash scripts/smoke-deploy1.sh '<ServerName do vhost>'"
    exit 2
fi
if [ ! -f "$LOG" ]; then
    echo "PARE: o log de erros do PHP informado nao existe (confira o 0r)"
    exit 2
fi

W=$(mktemp -d /tmp/fumaca-deploy1.XXXXXX) || { echo "PARE: mktemp falhou"; exit 2; }
trap 'rm -rf -- "${W:?}"' EXIT
chmod 700 "$W"
JAR="$W/cookies"
HDR="$W/cabecalho-csrf"
: > "$JAR"
: > "$HDR"
LOG_INICIO=$(stat -c %s "$LOG")
FALHAS=0

pare() {
    echo "PARE: $1"
    FALHAS=$((FALHAS + 1))
}

# GET da sessao: grava o token CSRF no arquivo de cabecalho e imprime
# "<id do cadastro> <perfil>" (o id interno, sem nome nem login).
sessao() {
    curl -s -H "Host: $HOST" -b "$JAR" -c "$JAR" "$BASE/api/session.php" \
        | php -r '$d = json_decode(stream_get_contents(STDIN), true); if (!is_array($d) || empty($d["csrf_token"])) { exit(1); } file_put_contents($argv[1], "X-CSRF-Token: " . $d["csrf_token"] . "\n"); echo (int)($d["ci"]["funcionario_id"] ?? 0), " ", (string)($d["role"] ?? "nenhum"), "\n";' -- "$HDR"
}

# POST JSON (corpo pela entrada padrao), com cookie e token; imprime o corpo
# e, na ultima linha, o codigo HTTP.
post_json() {
    curl -s -w '\n%{http_code}' -H "Host: $HOST" -H @"$HDR" -H 'Content-Type: application/json' \
        -b "$JAR" -c "$JAR" --data-binary @- "$BASE/$1"
}

codigo() { printf '%s' "$1" | tail -n 1; }
corpo() { printf '%s' "$1" | sed '$d'; }

# 1. Login de admin
sessao > /dev/null || { echo "PARE: session.php nao devolveu o token CSRF"; exit 1; }
read -rp 'Login AD do admin (allowlist): ' SMK_L
read -rsp 'Senha do AD: ' SMK_S
echo
R=$(SMK_L="$SMK_L" SMK_S="$SMK_S" php -r 'echo json_encode(["login_ad" => getenv("SMK_L"), "senha_ad" => getenv("SMK_S")]);' | post_json api/login_ci.php)
unset SMK_S SMK_L
echo "login: $(codigo "$R")"
[ "$(codigo "$R")" = 200 ] || { echo "PARE: login de admin falhou (codigo $(codigo "$R"))"; exit 1; }
read -r CADASTRO PERFIL < <(sessao) || { echo "PARE: session.php sem token depois do login"; exit 1; }
echo "perfil da sessao: $PERFIL; cadastro identificado: $([ "${CADASTRO:-0}" -gt 0 ] && echo sim || echo nao)"
[ "$PERFIL" = admin ] || pare "a sessao nao e de admin (login fora do AD_ADMIN_USERS?)"
[ "${CADASTRO:-0}" -gt 0 ] || pare "sessao sem cadastro (funcionario_id)"

# 2. Leituras com o codigo novo e o banco real
for U in api/status.php api/portal/schedules.php api/portal/pa_map.php api/portal/absences.php api/portal/calendar.php api/portal/reports.php; do
    C=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $HOST" -b "$JAR" -c "$JAR" "$BASE/$U")
    echo "GET /$U: $C"
    [ "$C" = 200 ] || pare "GET /$U respondeu $C"
done

# 3. Gravacao reversivel: folga de teste lancada e cancelada
if [ "$FALHAS" -eq 0 ]; then
    DIA=$(date -d '+30 days' +%F)
    R=$(SMK_E="$CADASTRO" SMK_D="$DIA" php -r 'echo json_encode(["action" => "create", "employee_id" => (int)getenv("SMK_E"), "starts_on" => getenv("SMK_D"), "ends_on" => getenv("SMK_D"), "category" => "folga", "period_type" => "full_day"]);' | post_json api/portal/absences.php)
    AUSENCIA=$(corpo "$R" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d) ? (int)($d["id"] ?? 0) : 0;')
    echo "ausencia de teste lancada: $(codigo "$R")"
    if [ "$(codigo "$R")" = 201 ] && [ "${AUSENCIA:-0}" -gt 0 ]; then
        R=$(SMK_A="$AUSENCIA" php -r 'echo json_encode(["action" => "cancel", "id" => (int)getenv("SMK_A")]);' | post_json api/portal/absences.php)
        echo "ausencia de teste cancelada: $(codigo "$R")"
        [ "$(codigo "$R")" = 200 ] || pare "a ausencia de teste ficou lancada (id interno $AUSENCIA): cancele pela tela Ausencias"
    else
        pare "a ausencia de teste nao foi lancada (codigo $(codigo "$R"))"
    fi
else
    echo "ausencia de teste: nao lancada (leitura falhou antes)"
fi

# 4. Sai da sessao
R=$(printf '{}' | post_json api/logout_ci.php)
echo "logout: $(codigo "$R")"
[ "$(codigo "$R")" = 200 ] || pare "logout respondeu $(codigo "$R")"

# 5. Log de erros do PHP: so a linha do login e esperada
NOVAS=$(tail -c +"$((LOG_INICIO + 1))" "$LOG" | grep -vc '\[AUTH_AD\] Login bem-sucedido')
LOGINS=$(tail -c +"$((LOG_INICIO + 1))" "$LOG" | grep -c '\[AUTH_AD\] Login bem-sucedido')
echo "linhas novas no log do PHP, fora o login: $NOVAS"
echo "logins bem-sucedidos no log: $LOGINS"
[ "$NOVAS" = 0 ] || pare "$NOVAS linha(s) nova(s) no log de erros do PHP (o responsavel le o log na VM; nao copie o conteudo)"

if [ "$FALHAS" -eq 0 ]; then
    echo "FUMACA OK"
else
    echo "PARE: $FALHAS falha(s) no teste de fumaca; nao reabra (6g)"
    exit 1
fi
