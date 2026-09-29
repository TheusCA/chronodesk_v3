#!/usr/bin/env bash
# ============================================================================
# Backup diario do ChronoDesk / Portal SDK (OPS-01)
#
# Gera, em um diretorio por execucao:
#   database.sql.gz  dump logico do MySQL (mysqldump --single-transaction,
#                    via docker exec, com usuario de backup somente leitura)
#   files.tar.gz     arquivos de estado da aplicacao (estado.json, pausas.csv,
#                    config_sistema.json, funcionarios.json) e diretorios
#                    privados (documentos e anexos de escala)
#   SHA256SUMS       somas de verificacao dos dois arquivos
#
# O .env NAO entra no backup: contem segredos e deve ser recuperavel do cofre.
#
# Uso (como root):  chronodesk-backup [arquivo-de-config]
#                   padrao: /etc/chronodesk/backup.env
# Modelo de config: deploy/backup/backup.env.example
# Instalacao, agendamento e restauracao: DEPLOY_LINUX.md, secao "Backup".
#
# Garantias:
#   - O diretorio final so aparece depois de todas as verificacoes; ate la ele
#     se chama <data>.partial e e removido em caso de falha.
#   - A retencao so roda depois de um backup completo e verificado, e so apaga
#     diretorios com o formato de nome gerado por este script.
#   - A senha do MySQL nunca aparece em linha de comando nem em variavel de
#     ambiente: o arquivo de opcoes e entregue ao mysqldump pela entrada padrao.
#
# Saida: mensagens de progresso sem segredos. Codigo 0 = backup completo.
# ============================================================================
set -Eeuo pipefail
umask 077

CONFIG_FILE="${1:-/etc/chronodesk/backup.env}"

MYSQL_CONTAINER=""
DB_NAME="sistema_pausas"
MYSQL_CNF="/etc/chronodesk/backup-mysql.cnf"
BACKUP_ROOT="/var/backups/chronodesk"
RETENTION_DAYS="14"
APP_DIR="/var/www/chronodesk"
PRIVATE_DIRS="/var/lib/chronodesk"

WORK_DIR=""
SUCCESS=0

log() { printf '%s %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$*"; }
fail() { log "FALHA: $*" >&2; exit 1; }

cleanup() {
    if [ "$SUCCESS" -ne 1 ] && [ -n "$WORK_DIR" ] && [ -d "$WORK_DIR" ]; then
        case "$WORK_DIR" in
            "$BACKUP_ROOT"/*.partial) rm -rf -- "$WORK_DIR" ;;
        esac
    fi
}
trap cleanup EXIT

# Le KEY=VALUE sem executar o arquivo (nada de "source"). So aceita as chaves
# conhecidas; qualquer outra aborta, para que erro de digitacao nao passe calado.
read_config() {
    local line key value
    [ -f "$CONFIG_FILE" ] || fail "arquivo de configuracao nao encontrado: $CONFIG_FILE"
    while IFS= read -r line || [ -n "$line" ]; do
        line="${line%$'\r'}"
        case "$line" in ''|'#'*|[[:space:]]'#'*) continue ;; esac
        [ "${line#*=}" != "$line" ] || continue
        key="${line%%=*}"
        value="${line#*=}"
        key="${key//[[:space:]]/}"
        value="${value#"${value%%[![:space:]]*}"}"
        value="${value%"${value##*[![:space:]]}"}"
        value="${value#[\"\']}"
        value="${value%[\"\']}"
        case "$key" in
            MYSQL_CONTAINER|DB_NAME|MYSQL_CNF|BACKUP_ROOT|RETENTION_DAYS|APP_DIR|PRIVATE_DIRS)
                printf -v "$key" '%s' "$value" ;;
            *) fail "chave desconhecida em $CONFIG_FILE: $key" ;;
        esac
    done < "$CONFIG_FILE"
}

require_absolute_dir_path() {
    case "$2" in
        /) fail "$1 nao pode ser a raiz do sistema" ;;
        /*) ;;
        *) fail "$1 deve ser caminho absoluto" ;;
    esac
}

validate_config() {
    [[ "$MYSQL_CONTAINER" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]] || fail "MYSQL_CONTAINER ausente ou invalido"
    [[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || fail "DB_NAME invalido"
    [[ "$RETENTION_DAYS" =~ ^[0-9]+$ ]] && [ "$RETENTION_DAYS" -ge 1 ] || fail "RETENTION_DAYS deve ser inteiro >= 1"
    require_absolute_dir_path BACKUP_ROOT "$BACKUP_ROOT"
    require_absolute_dir_path APP_DIR "$APP_DIR"
    [ -d "$APP_DIR" ] || fail "APP_DIR nao existe: $APP_DIR"

    local dir
    for dir in $PRIVATE_DIRS; do
        require_absolute_dir_path PRIVATE_DIRS "$dir"
        # Falha fechada: diretorio configurado e ausente indica config errada,
        # e um backup "completo" sem os documentos seria enganoso.
        [ -d "$dir" ] || fail "diretorio privado nao existe: $dir"
    done

    [ -f "$MYSQL_CNF" ] || fail "arquivo de credenciais do MySQL nao encontrado: $MYSQL_CNF"
    case "$(stat -c '%U %a' "$MYSQL_CNF")" in
        'root 600'|'root 400') ;;
        *) fail "$MYSQL_CNF deve pertencer a root com modo 600 ou 400" ;;
    esac
}

# Copia um arquivo de estado. Arquivos JSON sao validados apos a copia: a
# aplicacao reescreve estado.json por inteiro, e uma leitura no meio da escrita
# pegaria um arquivo truncado. Nesse caso tenta de novo.
copy_state_file() {
    local src="$1" dst="$2" attempt
    for attempt in 1 2 3; do
        cp -p -- "$src" "$dst"
        case "$src" in
            *.json)
                if php -r 'exit(json_decode((string)file_get_contents($argv[1])) === null ? 1 : 0);' "$dst"; then
                    return 0
                fi
                log "aviso: $(basename "$src") invalido na tentativa $attempt, repetindo"
                sleep 1
                ;;
            *) return 0 ;;
        esac
    done
    fail "$(basename "$src") continuou invalido apos 3 tentativas"
}

dump_database() {
    local out="$WORK_DIR/database.sql.gz"
    log "dump do banco $DB_NAME no container $MYSQL_CONTAINER"
    # --defaults-extra-file=/dev/stdin: o arquivo de opcoes vem pela entrada
    # padrao do docker exec, entao a senha nao aparece em ps nem em docker inspect.
    # --set-gtid-purged=OFF: o dump pode ser restaurado no mesmo servidor.
    # Sem --databases: o dump nao traz CREATE DATABASE/USE, entao o banco de
    # destino e escolhido na restauracao (inclusive um banco de teste).
    # O esquema nao tem procedures, functions, events nem views (verificado em
    # 2026-09-29); se passar a ter, acrescentar --routines/--events e os grants.
    docker exec -i "$MYSQL_CONTAINER" mysqldump \
        --defaults-extra-file=/dev/stdin \
        --single-transaction --quick --no-tablespaces --hex-blob \
        --set-gtid-purged=OFF --default-character-set=utf8mb4 \
        "$DB_NAME" \
        < "$MYSQL_CNF" | gzip -c > "$out"

    # Nada de "grep -q" depois de gzip: grep sairia no primeiro acerto, gzip
    # receberia SIGPIPE e, com pipefail, um dump valido seria dado como falho.
    gzip -t "$out" || fail "dump corrompido (gzip -t)"
    case "$(gzip -dc "$out" | tail -n 1)" in
        '-- Dump completed'*) ;;
        *) fail "dump incompleto: marcador final ausente" ;;
    esac
    [ "$(gzip -dc "$out" | grep -c '^CREATE TABLE `funcionarios`')" -ge 1 ] \
        || fail "dump sem a tabela funcionarios"
}

archive_files() {
    local staging="$WORK_DIR/staging" name rc dir
    local -a private_relative=()
    mkdir -p "$staging/app-state"

    for name in estado.json pausas.csv config_sistema.json funcionarios.json; do
        if [ -f "$APP_DIR/$name" ]; then
            copy_state_file "$APP_DIR/$name" "$staging/app-state/$name"
        else
            log "aviso: $name ausente em $APP_DIR, ignorado"
        fi
    done

    for dir in $PRIVATE_DIRS; do
        private_relative+=("${dir#/}")
    done

    log "arquivando estado da aplicacao e diretorios privados"
    # tar retorna 1 quando um arquivo muda durante a leitura (ex.: upload em
    # andamento). O arquivo continua integro; registra aviso e segue. >1 e erro.
    set +e
    tar --numeric-owner -czf "$WORK_DIR/files.tar.gz" \
        -C "$staging" app-state \
        -C / "${private_relative[@]}"
    rc=$?
    set -e
    [ "$rc" -le 1 ] || fail "tar terminou com codigo $rc"
    [ "$rc" -eq 0 ] || log "aviso: arquivo alterado durante o tar (codigo 1)"

    tar -tzf "$WORK_DIR/files.tar.gz" > /dev/null || fail "arquivo tar corrompido"
    rm -rf -- "$staging"
}

apply_retention() {
    # Somente diretorios com o nome gerado por este script. Parciais antigos
    # (queda da VM no meio de uma execucao) tambem saem apos 1 dia.
    find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d \
        -regextype posix-extended -regex '.*/[0-9]{8}-[0-9]{6}' \
        -mtime +"$RETENTION_DAYS" -print -exec rm -rf -- {} + \
        | sed 's/^/retencao: removido /'
    find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d \
        -regextype posix-extended -regex '.*/[0-9]{8}-[0-9]{6}\.partial' \
        -mtime +1 -print -exec rm -rf -- {} + \
        | sed 's/^/retencao: removido parcial /'
}

main() {
    [ "$(id -u)" -eq 0 ] || fail "execute como root"
    read_config
    validate_config

    local cmd
    for cmd in docker gzip tar sha256sum flock php; do
        command -v "$cmd" > /dev/null || fail "comando necessario ausente: $cmd"
    done
    [ "$(docker inspect -f '{{.State.Running}}' "$MYSQL_CONTAINER" 2>/dev/null)" = "true" ] \
        || fail "container $MYSQL_CONTAINER nao esta em execucao"

    install -d -m 700 "$BACKUP_ROOT"
    exec 9> "$BACKUP_ROOT/.lock"
    flock -n 9 || fail "outra execucao de backup esta em andamento"

    local stamp final
    stamp="$(date '+%Y%m%d-%H%M%S')"
    final="$BACKUP_ROOT/$stamp"
    WORK_DIR="$final.partial"
    [ ! -e "$final" ] && [ ! -e "$WORK_DIR" ] || fail "destino ja existe: $final"
    mkdir -m 700 "$WORK_DIR"

    dump_database
    archive_files

    (cd "$WORK_DIR" && sha256sum database.sql.gz files.tar.gz > SHA256SUMS && sha256sum -c --quiet SHA256SUMS) \
        || fail "verificacao SHA-256 falhou"

    mv -- "$WORK_DIR" "$final"
    WORK_DIR=""
    SUCCESS=1
    log "backup concluido: $final ($(du -sh "$final" | cut -f1))"

    apply_retention
}

main "$@"
