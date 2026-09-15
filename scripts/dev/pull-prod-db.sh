#!/usr/bin/env bash
# Прод-база → дев: дамп `stankoman` с боевого сервера заливается в локальную
# базу из .env, поверх догоняются миграции текущей ветки, сбрасывается кеш
# и пересобирается поисковый индекс дева.
#
#   ./scripts/dev/pull-prod-db.sh                    свежий дамп с прода
#   ./scripts/dev/pull-prod-db.sh --from <.sql.gz>   залить готовый дамп,
#                                                    в том числе откатиться
#                                                    на снимок дев-базы
#
# Перед заливкой текущая дев-база снимается в тот же каталог — это и есть откат.
# Дампы содержат персональные данные покупателей, поэтому лежат вне репозитория
# и доступны только владельцу. Хранится по KEEP_DUMPS последних каждого вида.

set -Eeuo pipefail

PROD_SSH_HOST="${PROD_SSH_HOST:-intertooler-production}"
PROD_ENV_FILE="${PROD_ENV_FILE:-/srv/www/intertooler/shared/.env}"
DUMP_DIR="${DUMP_DIR:-$HOME/.local/share/intertooler/db-dumps}"
KEEP_DUMPS="${KEEP_DUMPS:-5}"

# Содержимое этих таблиц на деве бессмысленно или вредно: кеш, сессии,
# очереди, токены сброса пароля. Структура переносится, данные — нет.
EPHEMERAL_TABLES="cache cache_locks sessions jobs job_batches failed_jobs password_reset_tokens"

die() { echo "ERROR: $*" >&2; exit 1; }
step() { printf '\n==> %s\n' "$*"; }

# Значение ключа из .env без обрамляющих кавычек.
env_value() {
    sed -n "s/^$2=//p" "$1" | tail -n 1 | sed -E "s/^\"(.*)\"\$/\\1/; s/^'(.*)'\$/\\1/"
}

FROM=""
while [ $# -gt 0 ]; do
    case "$1" in
        --from)
            [ -n "${2:-}" ] || die "--from требует путь к .sql.gz"
            FROM="$(realpath "$2")"
            shift 2
            ;;
        -h | --help)
            sed -n '2,/^$/{s/^# \{0,1\}//;p}' "$0"
            exit 0
            ;;
        *) die "неизвестный аргумент: $1" ;;
    esac
done

cd "$(dirname "$0")/../.."

[ -f .env ] || die "нет .env — скрипт живёт в репозитории intertooler"
[ "$(env_value .env APP_ENV)" = local ] || die "APP_ENV в .env не local — заливать прод-базу можно только на дев"

L_DB="$(env_value .env DB_DATABASE)"
L_USER="$(env_value .env DB_USERNAME)"
L_HOST="$(env_value .env DB_HOST)"
L_PORT="$(env_value .env DB_PORT)"
L_HOST="${L_HOST:-127.0.0.1}"
L_PORT="${L_PORT:-3306}"
MYSQL_PWD="$(env_value .env DB_PASSWORD)"
export MYSQL_PWD
[ -n "$L_DB" ] && [ -n "$L_USER" ] || die "в .env не заданы DB_DATABASE / DB_USERNAME"

# --skip-ssl-verify-server-cert: пароль идёт через MYSQL_PWD, клиент считает вход
# «беспарольным» и на каждый вызов печатает WARNING про отключённую проверку.
L_ARGS=(-u"$L_USER" -h"$L_HOST" -P"$L_PORT" --skip-ssl-verify-server-cert)

my() { mariadb "${L_ARGS[@]}" "$@" "$L_DB"; }

DUMP=""
BACKUP=""
DB_TOUCHED=0
on_exit() {
    local code=$?
    [ -n "$DUMP" ] && rm -f "$DUMP.part"
    if [ "$code" -ne 0 ] && [ "$DB_TOUCHED" = 1 ]; then
        echo >&2
        echo "Дев-база могла остаться в промежуточном состоянии. Откат:" >&2
        echo "  $0 --from $BACKUP" >&2
    fi
}
trap on_exit EXIT

umask 077
mkdir -p "$DUMP_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"

if [ -z "$FROM" ]; then
    ssh-add -l >/dev/null 2>&1 || die "в ssh-agent нет ключей — разблокируйте KeePassXC на Windows"

    DUMP="$DUMP_DIR/prod-$STAMP.sql.gz"
    EXPECTED_PASSES=2
    step "Дамп прод-базы с $PROD_SSH_HOST"

    # Скрипт уходит на сервер через stdin: пароль прод-базы читается там же
    # из shared/.env и не проходит ни через аргументы ssh, ни через этот терминал.
    ssh -o BatchMode=yes "$PROD_SSH_HOST" \
        "bash -s -- $(printf %q "$PROD_ENV_FILE") $(printf %q "$EPHEMERAL_TABLES")" \
        >"$DUMP.part" <<'REMOTE'
set -Eeuo pipefail
env_file="$1"
env_value() {
    sed -n "s/^$1=//p" "$env_file" | tail -n 1 | sed -E "s/^\"(.*)\"\$/\\1/; s/^'(.*)'\$/\\1/"
}
db="$(env_value DB_DATABASE)"
port="$(env_value DB_PORT)"
MYSQL_PWD="$(env_value DB_PASSWORD)"
export MYSQL_PWD
# Пользователь заведён только как …@127.0.0.1: без -h клиент идёт через сокет,
# представляется @localhost и получает Access denied. Пароль — через MYSQL_PWD:
# в нём есть @ и :, а в аргументах он ещё и виден в ps.
args=(-u"$(env_value DB_USERNAME)" -h127.0.0.1 -P"${port:-3306}"
    --single-transaction --quick --no-tablespaces
    --default-character-set=utf8mb4 --skip-ssl-verify-server-cert)
ignore=()
for table in $2; do ignore+=(--ignore-table="$db.$table"); done
# Два прохода: структура всех таблиц с routines/events/триггерами,
# затем данные без эфемерных таблиц.
{
    mariadb-dump "${args[@]}" --no-data --routines --events "$db"
    mariadb-dump "${args[@]}" --no-create-info --skip-triggers "${ignore[@]}" "$db"
} | nice -n 19 gzip -6
REMOTE

    mv "$DUMP.part" "$DUMP"
else
    DUMP="$FROM"
    EXPECTED_PASSES=1
fi

step "Проверка дампа $DUMP ($(du -h "$DUMP" | cut -f1))"
[ -f "$DUMP" ] || die "нет файла $DUMP"
gzip -t "$DUMP" || die "архив битый: $DUMP"
# Каждый проход mariadb-dump заканчивается этой строкой; оборванный поток
# её не содержит, а gzip -t такой обрыв пропускает, если архив закрыт.
PASSES="$(zcat "$DUMP" | grep -c '^-- Dump completed' || true)"
[ "$PASSES" -ge "$EXPECTED_PASSES" ] || die "дамп оборван: завершённых проходов $PASSES из $EXPECTED_PASSES"

BACKUP="$DUMP_DIR/dev-$STAMP.sql.gz"
step "Снимок текущей дев-базы $L_DB → $BACKUP"
mariadb-dump "${L_ARGS[@]}" \
    --single-transaction --quick --no-tablespaces --routines --events "$L_DB" | gzip -6 >"$BACKUP"

step "Заливка в $L_DB"
DB_TOUCHED=1
# Сносим всё, включая таблицы, которых на проде нет: таблица migrations
# приходит с прода, и миграция ветки упала бы на «table already exists».
{
    echo 'SET FOREIGN_KEY_CHECKS=0;'
    my -N -e "SELECT CONCAT('DROP ', IF(table_type = 'VIEW', 'VIEW', 'TABLE'), ' IF EXISTS \`', table_name, '\`;')
              FROM information_schema.tables WHERE table_schema = DATABASE()"
    echo 'SET FOREIGN_KEY_CHECKS=1;'
} | my
# У VIEW attribute_product_links в дампе DEFINER прод-пользователя: создать объект
# от чужого имени без SUPER нельзя, а без DEFINER view достаётся текущему.
zcat "$DUMP" | sed -E 's/DEFINER=`[^`]*`@`[^`]*`//g' | my

step "Миграции текущей ветки"
php artisan migrate --no-interaction

step "Сброс кешей"
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan event:clear

# Не cache:clear и не optimize:clear: у Redis-стора это FLUSHDB, а кеш-базу
# Redis (REDIS_CACHE_DB) делят все дев-проекты — ушёл бы кеш kratonshop,
# siteko и остальных. Удаляем только свои ключи; префикс и подключение
# спрашиваем у самого приложения, а не собираем из .env руками.
IFS='|' read -r CACHE_STORE_NAME REDIS_H REDIS_P REDIS_N CACHE_KEY_PREFIX REDIS_PASS < <(php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $store = Illuminate\Support\Facades\Cache::store();
    $conn = config("database.redis.".config("cache.stores.redis.connection", "cache"), []);
    echo implode("|", [
        config("cache.default"),
        $conn["host"] ?? "127.0.0.1",
        $conn["port"] ?? "6379",
        $conn["database"] ?? "0",
        config("database.redis.options.prefix").$store->getPrefix(),
        $conn["password"] ?? "",
    ]), "\n";
')
if [ "$CACHE_STORE_NAME" != redis ]; then
    echo "кеш не в Redis ($CACHE_STORE_NAME) — чистить нечего"
elif [[ "$CACHE_KEY_PREFIX" != *intertooler* ]]; then
    echo "WARN: подозрительный префикс кеша «$CACHE_KEY_PREFIX» — Redis не трогаю, почистите руками" >&2
else
    [ -n "$REDIS_PASS" ] && export REDISCLI_AUTH="$REDIS_PASS"
    redis_args=(-h "$REDIS_H" -p "$REDIS_P" -n "$REDIS_N")
    removed="$(redis-cli "${redis_args[@]}" --scan --pattern "$CACHE_KEY_PREFIX*" | wc -l)"
    redis-cli "${redis_args[@]}" --scan --pattern "$CACHE_KEY_PREFIX*" |
        xargs -r -d '\n' -n 500 redis-cli "${redis_args[@]}" UNLINK >/dev/null
    echo "Redis db $REDIS_N: удалено ключей $CACHE_KEY_PREFIX* — $removed"
fi

step "Поисковый индекс"
# Полная пересборка, а не search:audit --fix: в индексе дева лежат документы
# прежней базы, их надо стереть целиком. Пустой поиск на время сборки на деве
# никому не мешает — на бою так нельзя, см. CLAUDE.md.
php artisan products:search-reindex
# Без --fix это проверка: ненулевой код, если каталог и индекс разошлись.
php artisan search:audit

step "Уборка: храним по $KEEP_DUMPS последних дампов каждого вида"
for kind in prod dev; do
    find "$DUMP_DIR" -maxdepth 1 -name "$kind-*.sql.gz" -printf '%f\n' | sort -r |
        tail -n +"$((KEEP_DUMPS + 1))" | while read -r old; do
            rm -f "$DUMP_DIR/$old"
            echo "удалён $old"
        done
done

DB_TOUCHED=0
step "Готово"
my -e "SELECT 'products' AS t, COUNT(*) AS n FROM products
       UNION ALL SELECT 'categories', COUNT(*) FROM categories
       UNION ALL SELECT 'users', COUNT(*) FROM users
       UNION ALL SELECT 'orders', COUNT(*) FROM orders"
echo "дамп:  $DUMP"
echo "откат: $0 --from $BACKUP"
