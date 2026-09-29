#!/usr/bin/env sh
set -eu

BASE_URL="${BASE_URL:-http://chronodesk.interno.local}"

echo "== PHP syntax =="
find . -type f -name '*.php' -not -path './.git/*' -print | sort | while IFS= read -r file; do
    php -l "$file" >/dev/null
    echo "OK $file"
done

echo "== Shell syntax =="
bash -n scripts/backup-chronodesk.sh
sh -n scripts/preflight-deploy.sh
echo "OK scripts/backup-chronodesk.sh scripts/preflight-deploy.sh"

echo "== PHP auth QA =="
# Exige php-ldap; o script falha se a extensao estiver ausente.
php scripts/qa-auth.php

if command -v node >/dev/null 2>&1; then
    echo "== JavaScript syntax =="
    find . -type f -name '*.js' \
        -not -path './.git/*' \
        -not -path './frontend/node_modules/*' \
        -not -path './frontend/dist/*' \
        -print | sort | while IFS= read -r file; do
        node --check "$file" >/dev/null
        echo "OK $file"
    done
else
    echo "== JavaScript syntax =="
    echo "SKIP node not found"
fi

if command -v npm >/dev/null 2>&1 && [ -d frontend/node_modules ]; then
    echo "== React frontend =="
    (
        cd frontend
        npm run lint
        npm run qa:operational
        npm run build
    )
else
    echo "== React frontend =="
    echo "SKIP dependencies not installed (run: cd frontend && npm ci)"
fi

echo "== Git whitespace =="
git diff --check

check_status() {
    path="$1"
    expected="$2"
    url="${BASE_URL}${path}"
    status="$(curl -k -s -o /dev/null -w '%{http_code}' "$url")"
    echo "$status $path"

    case "$expected" in
        public)
            case "$status" in
                200|301|302|401|403) return 0 ;;
                *) echo "Unexpected status for $path: $status"; return 1 ;;
            esac
            ;;
        blocked)
            case "$status" in
                403|404) return 0 ;;
                *) echo "Sensitive path is not blocked: $path returned $status"; return 1 ;;
            esac
            ;;
        *)
            echo "Invalid expectation: $expected"
            return 1
            ;;
    esac
}

echo "== HTTP checks against $BASE_URL =="
check_status "/" public
check_status "/index.php" public
check_status "/login.php" public
check_status "/admin_login.php" public
check_status "/metricas.php" public
check_status "/admin.php" public
check_status "/api/status.php" public
check_status "/api/session.php" public
# Fora da allowlist responde 403; com o IP liberado, 200. 503 (banco fora) reprova.
check_status "/api/health.php" public
check_status "/api/portal/documents.php" public
check_status "/api/portal/critical_incidents.php" public
check_status "/api/portal/critical_incidents_export.php" public
check_status "/.env" blocked
check_status "/.git/config" blocked
check_status "/database_SECURED.sql" blocked
check_status "/README.md" blocked
check_status "/frontend/src/App.jsx" blocked
check_status "/frontend/vite.config.js" blocked
check_status "/frontend/package.json" blocked
check_status "/frontend/.env" blocked
check_status "/services/OperationalService.php" blocked
check_status "/services/DocumentService.php" blocked
check_status "/services/AdCredentialProvider.php" blocked
check_status "/services/CriticalIncidentService.php" blocked
check_status "/migrations/20260613_003_operational_modules.sql" blocked
check_status "/migrations/20260613_004_operational_hardening.sql" blocked
check_status "/migrations/20260613_005_documents_and_employee_roles.sql" blocked
check_status "/migrations/20260614_006_critical_incidents.sql" blocked
check_status "/backup.bak" blocked
check_status "/archive.zip" blocked
check_status "/secret.csv" blocked
check_status "/secret.json" blocked
check_status "/secret.xlsx" blocked
check_status "/secret.doc" blocked
check_status "/secret.docx" blocked
check_status "/uploads/test.pdf" blocked
check_status "/scripts/backup-chronodesk.sh" blocked
check_status "/deploy/backup/backup.env.example" blocked
check_status "/deploy/systemd/chronodesk-backup.service" blocked

legacy_post_status="$(curl -k -s -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/login.php")"
echo "$legacy_post_status POST /login.php"
case "$legacy_post_status" in
    3*) echo "Legacy POST must not be redirected"; exit 1 ;;
esac

# Modo de manutencao. Opt-in: derruba o portal por alguns segundos.
# Uso: sudo MAINTENANCE_TEST=1 sh scripts/test-linux.sh
# BASE_URL nao pode resolver para loopback, que passa pela manutencao de proposito.
echo "== Maintenance mode =="
if [ "${MAINTENANCE_TEST:-0}" != 1 ]; then
    echo "SKIP (rode com MAINTENANCE_TEST=1, como root, fora do horario de uso)"
else
    MAINTENANCE_FLAG="${MAINTENANCE_FLAG:-/var/www/chronodesk.maintenance}"
    if [ -e "$MAINTENANCE_FLAG" ]; then
        echo "FAIL: $MAINTENANCE_FLAG ja existe; o sistema ja esta em manutencao e o teste nao vai remove-lo"
        exit 1
    fi
    if [ ! -w "$(dirname "$MAINTENANCE_FLAG")" ]; then
        echo "FAIL: sem permissao para criar $MAINTENANCE_FLAG (rode como root)"
        exit 1
    fi

    maintenance_created=0
    cleanup_maintenance() {
        if [ "$maintenance_created" = 1 ]; then
            rm -f -- "$MAINTENANCE_FLAG"
        fi
    }
    trap cleanup_maintenance EXIT INT TERM

    base_scheme="${BASE_URL%%://*}"
    base_host="$(printf '%s' "$BASE_URL" | sed -E 's#^[A-Za-z]+://([^/:]+).*#\1#')"
    http_status() { curl -k -s -o /dev/null -w '%{http_code}' "$@"; }

    touch "$MAINTENANCE_FLAG"
    maintenance_created=1

    status="$(http_status "${BASE_URL}/")"
    echo "$status / (em manutencao)"
    [ "$status" = 503 ] || { echo "FAIL: esperado 503 com o arquivo-sinal presente"; exit 1; }

    body="$(curl -k -s "${BASE_URL}/api/health.php")"
    case "$body" in
        *"temporariamente indisponivel"*) echo "OK /api/health.php respondido pelo Apache, sem chegar ao PHP" ;;
        *) echo "FAIL: health chegou ao PHP em manutencao: $body"; exit 1 ;;
    esac

    status="$(http_status "${BASE_URL}/.env")"
    echo "$status /.env (em manutencao)"
    case "$status" in
        403|404) ;;
        *) echo "FAIL: caminho sensivel deve continuar bloqueado em manutencao"; exit 1 ;;
    esac

    status="$(http_status -H "Host: ${base_host}" "${base_scheme}://127.0.0.1/")"
    echo "$status / via loopback (em manutencao)"
    [ "$status" != 503 ] || { echo "FAIL: loopback deveria passar pela manutencao"; exit 1; }

    rm -f -- "$MAINTENANCE_FLAG"
    maintenance_created=0

    status="$(http_status "${BASE_URL}/")"
    echo "$status / (apos remover o arquivo-sinal)"
    [ "$status" != 503 ] || { echo "FAIL: portal continua em 503 sem o arquivo-sinal"; exit 1; }
fi

echo "All Linux validation checks passed."

