#!/usr/bin/env bash
# ============================================================================
# Ensaio de migration em banco descartavel (Lote 5b).
#
# Uso, no servidor, a partir de /var/www/chronodesk:
#   sudo bash scripts/ensaio-migration.sh <nome> <migration.sql> <rollback.sql> [tabelas]
#   sudo env PREPARO=<pre-requisito.sql> bash scripts/ensaio-migration.sh ...
#
#   <nome>      sufixo do banco de ensaio: cria ensaio_<nome> (a-z, 0-9, _).
#   [tabelas]   tabelas extras, separadas por virgula, copiadas so na
#               ESTRUTURA (CREATE TABLE ... LIKE), por exemplo audit_log.
#               funcionarios e sempre copiada com estrutura e dados (so as
#               colunas nao geradas).
#
# Roda a migration duas vezes e o rollback duas vezes e mostra, a cada etapa,
# o tipo das colunas de funcionarios, os indices, as tabelas, um checksum
# dos dados e contagens por perfil, equipe e situacao. Ao fim, apaga SO o
# banco ensaio_<nome> (tambem se o script for interrompido).
#
# A senha do root do MySQL e lida sem eco e passada em MYSQL_PWD so para os
# comandos. Nenhum dado pessoal e exibido: so estrutura, contagens e checksum.
# Os dados copiados ficam no mesmo servidor e somem no DROP do ensaio.
#
# Variaveis: CONTAINER (padrao Chrono_Desk_DB), ORIGEM (padrao sistema_pausas) e
# PREPARO (migration pre-requisito, aplicada uma vez antes do estado inicial;
# por exemplo a 016 para ensaiar o 016b).
# ============================================================================
set -u

CONTAINER=${CONTAINER:-Chrono_Desk_DB}
ORIGEM=${ORIGEM:-sistema_pausas}
NOME=${1:-}
MIGRATION=${2:-}
ROLLBACK=${3:-}
EXTRAS=${4:-}
PREPARO=${PREPARO:-}

uso() { echo "Uso: sudo bash scripts/ensaio-migration.sh <nome> <migration.sql> <rollback.sql> [tabelas]" >&2; exit 2; }
[ -n "$NOME" ] && [ -n "$MIGRATION" ] && [ -n "$ROLLBACK" ] || uso
printf %s "$NOME" | grep -Eq '^[a-z0-9_]{1,40}$' || { echo "Nome invalido: use a-z, 0-9 e _ (ate 40)." >&2; exit 2; }
[ -z "$EXTRAS" ] || printf %s "$EXTRAS" | grep -Eq '^[a-z0-9_]+(,[a-z0-9_]+)*$' || { echo "Tabelas invalidas." >&2; exit 2; }
for f in "$MIGRATION" "$ROLLBACK" ${PREPARO:+"$PREPARO"}; do
    [ -f "$f" ] || { echo "Arquivo nao encontrado: $f" >&2; exit 2; }
done

ENSAIO="ensaio_${NOME}"
read -rsp 'Senha root do MySQL: ' P; echo
export P

sql() {
    docker exec -i -e MYSQL_PWD="$P" "$CONTAINER" mysql -uroot --batch "$@"
}

# Exibicao em tabela; sql() sem bordas e para valores lidos pelo script.
mostra() {
    sql --table "$@"
}

CRIADO=0
limpar() {
    if [ "$CRIADO" = 1 ]; then
        echo "== Removendo o banco de ensaio ${ENSAIO}"
        sql -e "DROP DATABASE IF EXISTS \`${ENSAIO}\`;" && echo "Removido."
    fi
    unset P
}
trap limpar EXIT

EXISTE=$(sql --skip-column-names -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '${ENSAIO}';" | tr -dc '0-9')
if [ "$EXISTE" != 0 ]; then
    echo "O banco ${ENSAIO} ja existe. Confira e remova a mao, ou use outro nome." >&2
    exit 1
fi

echo "== Criando ${ENSAIO} a partir de ${ORIGEM}"
sql -e "CREATE DATABASE \`${ENSAIO}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || exit 1
CRIADO=1
for t in funcionarios $(printf %s "$EXTRAS" | tr ',' ' '); do
    sql -e "CREATE TABLE \`${ENSAIO}\`.\`${t}\` LIKE \`${ORIGEM}\`.\`${t}\`;" || exit 1
done
COLUNAS=$(sql --skip-column-names -e "SELECT GROUP_CONCAT(CONCAT('\`', COLUMN_NAME, '\`') ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '${ORIGEM}' AND TABLE_NAME = 'funcionarios' AND EXTRA NOT LIKE '%GENERATED%';" | tr -d '\r')
sql -e "INSERT INTO \`${ENSAIO}\`.funcionarios (${COLUNAS}) SELECT ${COLUNAS} FROM \`${ORIGEM}\`.funcionarios;" || exit 1

estado() {
    echo
    echo "== Estado: $1"
    mostra -e "SELECT COLUMN_NAME AS coluna, COLUMN_TYPE AS tipo FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '${ENSAIO}' AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME IN ('access_role', 'ad_login', 'ad_login_ativo', 'equipe', 'ativo') ORDER BY ORDINAL_POSITION;"
    mostra -e "SELECT TABLE_NAME AS tabela, INDEX_NAME AS indice, IF(NON_UNIQUE = 0, 'unico', '') AS unico, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS colunas FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '${ENSAIO}' GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE ORDER BY TABLE_NAME, INDEX_NAME;"
    mostra -e "SELECT TABLE_NAME AS tabela FROM information_schema.TABLES WHERE TABLE_SCHEMA = '${ENSAIO}' ORDER BY TABLE_NAME;"
    mostra -e "CHECKSUM TABLE \`${ENSAIO}\`.funcionarios;"
    mostra -e "SELECT equipe, access_role, ativo, COUNT(*) AS funcionarios FROM \`${ENSAIO}\`.funcionarios GROUP BY equipe, access_role, ativo ORDER BY equipe, access_role, ativo;"
    if printf %s ",${EXTRAS}," | grep -q ',audit_log,'; then
        mostra -e "SELECT action AS evento, severity, COUNT(*) AS eventos FROM \`${ENSAIO}\`.audit_log GROUP BY action, severity ORDER BY action;"
    fi
}

etapa() {
    echo
    echo "== Executando: $1 ($2)"
    if sql "$ENSAIO" < "$2"; then
        echo "-> $1: OK"
    else
        echo "-> $1: FALHOU (veja a mensagem acima)"
    fi
    estado "depois de $1"
}

if [ -n "$PREPARO" ]; then
    echo
    echo "== Preparo: ${PREPARO}"
    sql "$ENSAIO" < "$PREPARO" || { echo "Preparo falhou; ensaio interrompido."; exit 1; }
fi

estado "inicial (copia de ${ORIGEM}${PREPARO:+, com o preparo})"
etapa "migration, 1a vez" "$MIGRATION"
etapa "migration, 2a vez" "$MIGRATION"
etapa "rollback, 1a vez" "$ROLLBACK"
etapa "rollback, 2a vez" "$ROLLBACK"
echo
echo "== Fim do ensaio. Compare: as duas execucoes da migration devem dar o mesmo estado; as do rollback tambem."
