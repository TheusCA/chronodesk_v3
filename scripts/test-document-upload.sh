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
no_csrf_response="$(mktemp)"
mime_response="$(mktemp)"
oversize_response="$(mktemp)"
delete_response="$(mktemp)"
downloaded_file="$(mktemp)"
mime_mismatch_file="$(mktemp --suffix=.pdf)"
oversize_file="$(mktemp --suffix=.txt)"
trap 'rm -f "$allowed_response" "$blocked_response" "$no_csrf_response" "$mime_response" "$oversize_response" "$delete_response" "$downloaded_file" "$mime_mismatch_file" "$oversize_file"' EXIT

printf 'conteudo textual que nao e PDF\n' > "$mime_mismatch_file"
dd if=/dev/zero of="$oversize_file" bs=1M count=11 status=none

allowed_status="$(request_upload "$ALLOWED_FILE" "$allowed_response")"
echo "$allowed_status upload permitido"
if [ "$allowed_status" != "201" ]; then
    cat "$allowed_response"
    exit 1
fi
document_id="$(php -r '$data=json_decode(file_get_contents($argv[1]), true); echo (int)($data["document"]["id"] ?? 0);' "$allowed_response")"
if [ "$document_id" -lt 1 ]; then
    echo "Resposta do upload permitido nao retornou ID valido."
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

no_csrf_status="$(curl -k -sS \
    -o "$no_csrf_response" \
    -w '%{http_code}' \
    -b "$COOKIE_JAR" \
    -F "document=@${ALLOWED_FILE}" \
    -F "title=QA sem CSRF" \
    -F "category=QA" \
    "${BASE_URL}/api/portal/documents.php")"
echo "$no_csrf_status upload sem CSRF"
if [ "$no_csrf_status" != "403" ]; then
    cat "$no_csrf_response"
    exit 1
fi

mime_status="$(request_upload "$mime_mismatch_file" "$mime_response")"
echo "$mime_status upload com MIME divergente"
if [ "$mime_status" != "400" ]; then
    cat "$mime_response"
    exit 1
fi

oversize_status="$(request_upload "$oversize_file" "$oversize_response")"
echo "$oversize_status upload acima do limite"
if [ "$oversize_status" != "413" ]; then
    cat "$oversize_response"
    exit 1
fi

no_session_status="$(curl -k -s -o /dev/null -w '%{http_code}' "${BASE_URL}/api/portal/documents.php")"
echo "$no_session_status documentos sem sessao"
if [ "$no_session_status" != "401" ]; then
    exit 1
fi

download_status="$(curl -k -sS \
    -o "$downloaded_file" \
    -w '%{http_code}' \
    -b "$COOKIE_JAR" \
    "${BASE_URL}/api/portal/documents_download.php?id=${document_id}")"
echo "$download_status download autorizado"
if [ "$download_status" != "200" ] || [ ! -s "$downloaded_file" ]; then
    exit 1
fi

unauthorized_download_status="$(curl -k -s -o /dev/null -w '%{http_code}' "${BASE_URL}/api/portal/documents_download.php?id=${document_id}")"
echo "$unauthorized_download_status download sem sessao"
if [ "$unauthorized_download_status" != "401" ]; then
    exit 1
fi

invalid_download_status="$(curl -k -s -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" "${BASE_URL}/api/portal/documents_download.php?id=../../etc/passwd")"
echo "$invalid_download_status download com ID invalido"
if [ "$invalid_download_status" != "400" ]; then
    exit 1
fi

delete_status="$(curl -k -sS \
    -o "$delete_response" \
    -w '%{http_code}' \
    -b "$COOKIE_JAR" \
    -H "Content-Type: application/json" \
    -H "X-CSRF-Token: $CSRF_TOKEN" \
    --data "{\"id\":${document_id}}" \
    "${BASE_URL}/api/portal/documents_delete.php")"
echo "$delete_status exclusao autorizada"
if [ "$delete_status" != "200" ]; then
    cat "$delete_response"
    exit 1
fi

deleted_download_status="$(curl -k -s -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" "${BASE_URL}/api/portal/documents_download.php?id=${document_id}")"
echo "$deleted_download_status download apos exclusao"
if [ "$deleted_download_status" != "404" ]; then
    exit 1
fi

echo "Testes HTTP de documentos concluidos."
