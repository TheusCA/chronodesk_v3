#!/usr/bin/env bash
# ============================================================================
# Medicao da carga do polling de 15 s (api/status.php) — Parte B, F2 (P14) e
# PERF-01. Mede o tempo de resposta visto da propria VM, antes e depois do
# deploy do F2, com a mesma sessao e o mesmo N.
#
# Uso, no servidor, fora do horario de uso:
#   bash scripts/medir-polling.sh
#   N=200 CONSULTAS=1 bash scripts/medir-polling.sh
# Antes do deploy do F2 (o script nao depende do codigo da aplicacao):
#   bash <(git show origin/<BRANCH>:scripts/medir-polling.sh)
#
# Sessao: o valor do cookie de uma sessao ja aberta no navegador (ver
# DEPLOY_LINUX.md, "Medicao do polling"). E lido do terminal sem eco, so
# aceito com os caracteres de um ID de sessao do PHP, passado ao curl pela
# entrada padrao (curl -K -) e apagado da memoria no fim. Nunca vai para
# argumento de comando (ps), arquivo, historico do shell nem saida. O curl
# nao grava cookie (sem -c) e o corpo das respostas, que tem nomes, vai para
# /dev/null.
#
# Variaveis: URL (padrao http://127.0.0.1/api/status.php), HOST_HEADER (padrao
# chronodesk.interno.local), COOKIE_NOME (padrao PHPSESSID), N (padrao 200),
# AQUECIMENTO (padrao 5, nao entram na conta), INTERVALO (segundos entre
# requisicoes, padrao 0), CONSULTAS=1 (consultas ao MySQL por requisicao, pelo
# performance_schema; pede a senha do root do MySQL), CONTAINER (padrao
# Chrono_Desk_DB), BANCO (padrao sistema_pausas). ENTRADA_TERMINAL (padrao
# /dev/tty) existe so para o teste automatizado do script.
#
# Saida: so numeros (HTTP, tempos em ms, tamanho em bytes, consultas). Nenhum
# dado pessoal e nenhum cookie.
#
# Validade da sessao: o ID troca a cada 30 min de idade da sessao
# (security.php) e a sessao de tecnico cai com 30 min sem uso. Medir logo
# depois do login; se o ID trocar no meio, as requisicoes seguintes dao 401 e
# o script avisa no fim.
# ============================================================================
# Sem xtrace mesmo com "bash -x" ou SHELLOPTS: o trace mostraria o cookie e a
# senha expandidos na linha do printf e do docker.
set +x
set -Eeuo pipefail

URL=${URL:-http://127.0.0.1/api/status.php}
HOST_HEADER=${HOST_HEADER:-chronodesk.interno.local}
COOKIE_NOME=${COOKIE_NOME:-PHPSESSID}
N=${N:-200}
AQUECIMENTO=${AQUECIMENTO:-5}
INTERVALO=${INTERVALO:-0}
CONSULTAS=${CONSULTAS:-0}
CONTAINER=${CONTAINER:-Chrono_Desk_DB}
BANCO=${BANCO:-sistema_pausas}
ENTRADA_TERMINAL=${ENTRADA_TERMINAL:-/dev/tty}

erro() { echo "$1" >&2; exit 2; }
printf %s "$N" | grep -Eq '^[1-9][0-9]{0,4}$' || erro "N invalido (1 a 99999)."
printf %s "$AQUECIMENTO" | grep -Eq '^[0-9]{1,3}$' || erro "AQUECIMENTO invalido."
printf %s "$INTERVALO" | grep -Eq '^[0-9]+(\.[0-9]+)?$' || erro "INTERVALO invalido."
printf %s "$COOKIE_NOME" | grep -Eq '^[A-Za-z0-9_]{1,64}$' || erro "COOKIE_NOME invalido."
printf %s "$HOST_HEADER" | grep -Eq '^[A-Za-z0-9.-]{1,253}$' || erro "HOST_HEADER invalido."
printf %s "$BANCO" | grep -Eq '^[a-z0-9_]{1,64}$' || erro "BANCO invalido."
case "$URL" in http://*|https://*) ;; *) erro "URL deve comecar com http:// ou https://." ;; esac
command -v curl >/dev/null || erro "curl nao encontrado."

COOKIE=''
P=''
trap 'COOKIE=""; P=""; unset COOKIE P' EXIT

# Do terminal, mesmo quando o script vem por <(git show ...).
read -rsp "Valor do cookie ${COOKIE_NOME} (nao aparece na tela): " COOKIE < "$ENTRADA_TERMINAL"; echo
# ID de sessao do PHP: letras, digitos, virgula e hifen. Rejeitar o resto
# impede aspas ou quebra de linha na configuracao do curl.
printf %s "$COOKIE" | grep -Eq '^[A-Za-z0-9,-]{16,256}$' || erro "Valor de cookie invalido: cole so o valor, sem o nome nem ';'."

# Uma requisicao: devolve "http tempo_s bytes". A configuracao com o cookie
# chega ao curl pela entrada padrao; printf e builtin do bash (nao vira processo).
requisicao() {
    printf 'cookie = "%s=%s"\n' "$COOKIE_NOME" "$COOKIE" \
        | curl -K - -s -o /dev/null --max-time 30 -H "Host: ${HOST_HEADER}" \
            -w '%{http_code} %{time_total} %{size_download}\n' "$URL"
}

# Senha pelo ambiente do cliente docker (-e MYSQL_PWD sem valor), fora dos
# argumentos do comando.
consultas_total() {
    MYSQL_PWD="$P" docker exec -i -e MYSQL_PWD "$CONTAINER" mysql -uroot --batch --skip-column-names \
        -e "SELECT COALESCE(SUM(COUNT_STAR), 0) FROM performance_schema.events_statements_summary_by_digest WHERE SCHEMA_NAME = '${BANCO}';" \
        | tr -dc '0-9'
}

# Sessao valida antes de medir.
read -r codigo _ _ <<< "$(requisicao)"
if [ "$codigo" != 200 ]; then
    erro "A primeira requisicao respondeu HTTP ${codigo} (401 = sessao expirada ou cookie errado). Nada foi medido."
fi

if [ "$CONSULTAS" = 1 ]; then
    command -v docker >/dev/null || erro "docker nao encontrado (CONSULTAS=1)."
    read -rsp 'Senha root do MySQL (CONSULTAS=1): ' P < "$ENTRADA_TERMINAL"; echo
fi

for _ in $(seq 1 "$AQUECIMENTO"); do requisicao >/dev/null; done

antes_consultas=0
[ "$CONSULTAS" = 1 ] && antes_consultas=$(consultas_total)

tempos=()
bytes_total=0
falhas=0
inicio=$(date +%s)
for _ in $(seq 1 "$N"); do
    read -r codigo tempo bytes <<< "$(requisicao)"
    if [ "$codigo" != 200 ]; then
        falhas=$((falhas + 1))
    else
        tempos+=("$tempo")
        bytes_total=$((bytes_total + ${bytes%.*}))
    fi
    [ "$INTERVALO" = 0 ] || sleep "$INTERVALO"
done
duracao=$(( $(date +%s) - inicio ))

depois_consultas=0
[ "$CONSULTAS" = 1 ] && depois_consultas=$(consultas_total)

ok=${#tempos[@]}
echo
echo "== Medicao do polling: $(date '+%Y-%m-%d %H:%M:%S %z')"
echo "URL: ${URL} (Host: ${HOST_HEADER})"
echo "Requisicoes: ${N} (aquecimento ${AQUECIMENTO} fora da conta); HTTP 200: ${ok}; outras: ${falhas}; duracao: ${duracao} s"
if [ "$ok" -gt 0 ]; then
    printf '%s\n' "${tempos[@]}" | sort -n | awk -v n="$ok" -v bytes="$bytes_total" '
        { t[NR] = $1 * 1000; soma += $1 * 1000 }
        END {
            p50 = t[int((n - 1) * 0.50) + 1]
            p95 = t[int((n - 1) * 0.95) + 1]
            printf "Tempo (ms): p50 %.1f | p95 %.1f | max %.1f | media %.1f\n", p50, p95, t[n], soma / n
            printf "Tamanho medio da resposta: %d bytes\n", bytes / n
        }'
fi
if [ "$CONSULTAS" = 1 ]; then
    total=$((depois_consultas - antes_consultas))
    echo "Consultas ao MySQL no periodo: ${total} (~$(awk -v t="$total" -v n="$N" 'BEGIN { printf "%.1f", t / n }') por requisicao; inclui outro trafego no banco)"
fi
echo "Criterio do F2 (decisao de 2026-10-05): piora = p95 sobe mais de 50 ms E mais de 20% em relacao a base."
[ "$falhas" = 0 ] || echo "ATENCAO: ${falhas} requisicao(oes) sem HTTP 200; a sessao pode ter expirado no meio."
