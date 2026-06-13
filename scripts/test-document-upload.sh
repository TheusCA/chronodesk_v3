#!/usr/bin/env sh
set -eu

BASE_URL="${BASE_URL:-http://chronodesk.interno.local}"
COOKIE_JAR="${COOKIE_JAR:-}"
CSRF_TOKEN="${CSRF_TOKEN:-}"
ALLOWED_FILE="${ALLOWED_FILE:-}"
BLOCKED_FILE="${BLOCKED_FILE:-}"

if [ -z "$COOKIE_JAR" ] || [ -z "$CSRF_TOKEN" ] || [ -z "$ALLOWED_FILE" ] || [ -z "$BLOCKED_FILE" ]; then
    echo "Uso: COOKIE_JAR=/tmp/cookies CSRF_TOKEN=... ALLOWED_FILE=/tmp/manual.pdf BLOCKED_FILE=/tmp/payload.php $0"
    exit 2
fi

for file in "$COOKIE_JAR" "$ALLOWED_FILE" "$BLOCKED_FILE"; do
    if [ ! -f "$file" ]; then
        echo "Arquivo de teste nao encontrado: $file"
        exit 2
    fi
done

request_upload() {
    file="$1"
    output="$2"
    curl -k -sS \
        -o "$output" \
        -w '%{http_code}' \
        -b "$COOKIE_JAR" \
        -H "X-CSRF-Token: $CSRF_TOKEN" \
        -F "document=@${file}" \
        -F "title=QA upload" \
        -F "category=QA" \
        -F "description=Validacao controlada de upload" \
        -F "visibility=internal" \
        "${BASE_URL}/api/portal/documents.php"
}

allowed_response="$(mktemp)"
blocked_response="$(mktemp)"
trap 'rm -f "$allowed_response" "$blocked_response"' EXIT

allowed_status="$(request_upload "$ALLOWED_FILE" "$allowed_response")"
echo "$allowed_status upload permitido"
if [ "$allowed_status" != "201" ]; then
    cat "$allowed_response"
    exit 1
fi

blocked_status="$(request_upload "$BLOCKED_FILE" "$blocked_response")"
echo "$blocked_status upload bloqueado"
case "$blocked_status" in
    400|413) ;;
    *)
        cat "$blocked_response"
        exit 1
        ;;
esac

no_session_status="$(curl -k -s -o /dev/null -w '%{http_code}' "${BASE_URL}/api/portal/documents.php")"
echo "$no_session_status documentos sem sessao"
if [ "$no_session_status" != "401" ]; then
    exit 1
fi

invalid_download_status="$(curl -k -s -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" "${BASE_URL}/api/portal/documents_download.php?id=../../etc/passwd")"
echo "$invalid_download_status download com ID invalido"
if [ "$invalid_download_status" != "400" ]; then
    exit 1
fi

echo "Testes HTTP de documentos concluidos."
