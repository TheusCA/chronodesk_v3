#!/usr/bin/env sh
set -eu

BASE_URL="${BASE_URL:-http://chronodesk.interno.local}"

echo "== PHP syntax =="
find . -type f -name '*.php' -not -path './.git/*' -print | sort | while IFS= read -r file; do
    php -l "$file" >/dev/null
    echo "OK $file"
done

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
check_status "/api/portal/documents.php" public
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
check_status "/migrations/20260613_003_operational_modules.sql" blocked
check_status "/migrations/20260613_004_operational_hardening.sql" blocked
check_status "/migrations/20260613_005_documents_and_employee_roles.sql" blocked
check_status "/backup.bak" blocked
check_status "/archive.zip" blocked
check_status "/secret.csv" blocked
check_status "/secret.json" blocked
check_status "/secret.xlsx" blocked
check_status "/secret.docx" blocked
check_status "/uploads/test.pdf" blocked

legacy_post_status="$(curl -k -s -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/login.php")"
echo "$legacy_post_status POST /login.php"
case "$legacy_post_status" in
    3*) echo "Legacy POST must not be redirected"; exit 1 ;;
esac

echo "All Linux validation checks passed."

