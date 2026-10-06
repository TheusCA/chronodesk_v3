#!/usr/bin/env bash
# ============================================================================
# QA de comportamento do scripts/medir-polling.sh, sem servidor real nem
# sessao real: um servidor PHP embutido no loopback responde 200 so para o
# cookie de teste e 401 para o resto. Confere que o cookie de teste nunca
# aparece na saida (nem com "bash -x"), que nada e gravado no diretorio de
# trabalho nem no HOME, e que cookie invalido ou sessao recusada param o
# script sem medir.
#
# Uso: bash scripts/qa-medir-polling.sh   (exige php e curl)
# ============================================================================
set -Eeuo pipefail

RAIZ=$(cd "$(dirname "$0")/.." && pwd)
SCRIPT="$RAIZ/scripts/medir-polling.sh"
COOKIE_TESTE='qa0medicao0cookie0de0teste0f2'

tmp=$(mktemp -d)
servidor_pid=''
limpar() {
    [ -z "$servidor_pid" ] || kill "$servidor_pid" 2>/dev/null || true
    rm -rf -- "${tmp:?}"
}
trap limpar EXIT

mkdir "$tmp/cwd" "$tmp/home" "$tmp/www"
cat > "$tmp/www/router.php" <<'PHP'
<?php
// 200 so com o cookie de teste e o Host esperado; 401 no resto.
$ok = ($_COOKIE['PHPSESSID'] ?? '') === getenv('QA_COOKIE')
    && ($_SERVER['HTTP_HOST'] ?? '') === 'chronodesk.interno.local';
http_response_code($ok ? 200 : 401);
header('Content-Type: application/json');
echo $ok ? '{"n1":[],"n2":[]}' : '{"sucesso":false}';
PHP

porta=$((20000 + RANDOM % 20000))
QA_COOKIE="$COOKIE_TESTE" php -S "127.0.0.1:$porta" "$tmp/www/router.php" >/dev/null 2>&1 &
servidor_pid=$!
for _ in $(seq 1 50); do
    curl -s -o /dev/null "http://127.0.0.1:$porta/" && break
    sleep 0.1
done

falhas=0
confere() { # descricao, resultado (0 = passou)
    if [ "$2" = 0 ]; then echo "OK   $1"; else echo "FALHA $1"; falhas=$((falhas + 1)); fi
}

# Roda o script com o valor dado no lugar do terminal; devolve a saida
# combinada em $tmp/saida e o codigo de saida em $codigo.
rodar() { # valor_do_cookie, [interpretador extra...]
    printf '%s\n' "$1" > "$tmp/entrada"
    shift
    set +e
    (cd "$tmp/cwd" && HOME="$tmp/home" URL="http://127.0.0.1:$porta/api/status.php" N=5 AQUECIMENTO=1 \
        ENTRADA_TERMINAL="$tmp/entrada" bash "$@" "$SCRIPT") > "$tmp/saida" 2>&1
    codigo=$?
    set -e
}
sem_cookie_na_saida() { ! grep -qF "$COOKIE_TESTE" "$tmp/saida"; }
nada_gravado() { [ -z "$(ls -A "$tmp/cwd")" ] && [ -z "$(ls -A "$tmp/home")" ]; }

rodar "$COOKIE_TESTE"
confere 'sessao valida: termina com 0' "$([ "$codigo" = 0 ]; echo $?)"
confere 'sessao valida: 5 requisicoes com HTTP 200' "$(grep -q 'HTTP 200: 5; outras: 0' "$tmp/saida"; echo $?)"
confere 'sessao valida: mostra p95' "$(grep -q 'p95' "$tmp/saida"; echo $?)"
confere 'sessao valida: cookie fora da saida' "$(sem_cookie_na_saida; echo $?)"
confere 'sessao valida: nada gravado no diretorio de trabalho nem no HOME' "$(nada_gravado; echo $?)"

rodar "$COOKIE_TESTE" -x
confere 'bash -x: termina com 0' "$([ "$codigo" = 0 ]; echo $?)"
confere 'bash -x: cookie fora da saida (xtrace desligado no script)' "$(sem_cookie_na_saida; echo $?)"

rodar 'qa0cookie0que0o0servidor0recusa'
confere 'sessao recusada: termina com 2' "$([ "$codigo" = 2 ]; echo $?)"
confere 'sessao recusada: avisa o 401 e nao mede' "$(grep -q 'HTTP 401' "$tmp/saida" && ! grep -q 'p95' "$tmp/saida"; echo $?)"

rodar 'PHPSESSID=qa0medicao0cookie0de0teste0f2'
confere 'nome junto do valor: recusado com 2' "$([ "$codigo" = 2 ]; echo $?)"
rodar 'qa0medicao0cookie"\nurl = "http://exemplo.invalid/'
confere 'aspas no valor (injecao na configuracao do curl): recusado com 2' "$([ "$codigo" = 2 ] && grep -q 'Valor de cookie invalido' "$tmp/saida"; echo $?)"
rodar 'curto'
confere 'valor curto demais: recusado com 2' "$([ "$codigo" = 2 ]; echo $?)"

if [ "$falhas" != 0 ]; then
    echo "qa-medir-polling: $falhas falha(s)"
    exit 1
fi
echo 'qa-medir-polling OK'
