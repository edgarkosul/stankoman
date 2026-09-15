# CLAUDE.md — intertooler

Интернет-магазин промышленного оборудования (станки, инструмент) — intertooler.ru.

> **Общие правила кода** (Laravel/Filament/Livewire/Pest/Tailwind, PHP-стиль,
> запуск Pint и тестов) лежат в [`.github/copilot-instructions.md`](.github/copilot-instructions.md) —
> это файл, который генерирует Laravel Boost. Здесь его не дублируем; здесь то,
> что специфично для этого проекта и из кода не выводится.

## Стек

Laravel 12 + Livewire 4 + Filament 5, PHP 8.4, MariaDB, Redis (кэш/сессии/очередь),
Meilisearch через Scout, Tailwind v4 (CSS-first, без `tailwind.config.js`), Vite 7.

## Наследие в именах

Проект вырос из старого сайта stankoman.ru, поэтому вокруг много «чужих» имён —
это не ошибка и переименовывать не надо:

- git-remote `origin` → `git@github.com:edgarkosul/stankoman.git`;
- боевая БД называется `stankoman`, индекс Meilisearch — `stankoman_products`;
- домен stankoman.ru живым DNS больше не отвечает, это просто редирект на intertooler;
- в каталоге есть слой legacy-товаров kraton (см. ниже).

## Архитектура

### Витрина

`routes/web.php` — около полутора десятков маршрутов, страницы собраны на Livewire
(`app/Livewire/`): каталог (`Pages\Categories\LeafCategoryPage`), корзина, избранное,
сравнение, заказы, мастер оформления заказа (`Checkout\Wizard`). Бизнес-логика вынесена
в сервисы `app/Support/*Service.php` (`CartService`, `CheckoutService`,
`OrderPlacementService`, `CompareService`, `FavoritesService`, `ProductFilterService`),
компоненты их только зовут.

Каталог живёт на одном маршруте `/catalog/{path?}` с `where('path', '.*')` — путь
разбирается в дерево категорий приложением, а не роутером.

### Админка

Filament 5, ресурсы в `app/Filament/Resources/`. Доступ в панель даёт `canAccessPanel`
по списку e-mail из настроек, не по ролям.

**Осторожно с окружением:** на бою `FILAMENT_ADMIN_EMAILS` и `SHOP_MANAGER_EMAILS`
заданы на уровне ОС/php-fpm и **перебивают `.env`** (phpdotenv не переопределяет уже
установленную переменную). Если доступы ведут себя «не как в .env» — смотреть
`grep -r FILAMENT_ADMIN_EMAILS /etc/php /etc/systemd`, а не только `.env`.

Отсюда же регулярная путаница: middleware `EnsureStorefrontCustomer` уводит
залогиненного админа/менеджера с `/checkout` на главную. Это **так задумано** —
у гостей и обычных покупателей оформление работает. В деве не воспроизводится,
потому что там эти переменные пустые.

### Каталог и поиск

Поиск — Scout + Meilisearch. Драйвер `collection` фильтры игнорирует, поэтому
Meilisearch нужен и в деве тоже (`SCOUT_DRIVER=meilisearch`, локально
`http://127.0.0.1:7703`).

Синхронизацию индекса держит `App\Support\Products\ProductSearchSync` — все живые
места (импорт, Filament, ремонтные команды) зовут его сразу, это не фоновая задача.
Ночью в 04:45 идёт `search:audit --fix`: она сначала **докладывает** расхождение
каталога с индексом в `storage/logs/search-audit.log` и только потом чинит.

Полная пересборка `products:search-reindex` осталась **ручной командой**: она
начинается с `removeAllFromSearch()`, то есть на всё время сборки поиск на сайте
отдаёт пустоту. В расписании её быть не должно.

Перед сверкой в 04:40 идёт `scout:sync-index-settings` — иначе новые фильтруемые
поля доедут до Meilisearch только руками.

### Импорт товаров

Самая большая подсистема. Слои:

- команда-вход `catalog:import-products {supplier} [--queue --write --mode=...]`,
  где supplier — `vactool`, `metalmaster` или `yandex_market_feed`;
- `app/Support/CatalogImport/`, `app/Support/{Vactool,Metalmaster,Metaltec}/` — парсеры;
- `app/Jobs/Run*ImportJob.php` — прогоны в очереди. Metaltec и Stalex своей ветки
  в команде не имеют, у них только джобы;
- `config/catalog-import.php` — ключи `schedule` (в расписании стоят только vactool
  и metalmaster), `media`, `feed_upload`;
- модели `ImportRun`, `ImportRunEvent`, `ImportIssue`, `ImportMediaIssue`,
  `ImportFeedSource`, `SupplierImportSource` — журнал прогонов, он же виден в админке.

Без `--write` это dry-run: команда показывает план изменений и ничего не пишет.

### Legacy kraton

Со старого сайта kratonkuban.ru идут редиректы. Резолвер —
`/_legacy/kraton/resolve` (`LegacyKratonRedirectController`), сопоставление товаров
ночью — `legacy:kraton-match`. План миграции: [`docs/legacy-kraton-redirect-plan.md`](docs/legacy-kraton-redirect-plan.md).

**Важно для nginx:** резолвер ходит с `127.0.0.1` и даёт до 212 запросов в минуту.
Любой `limit_req` по IP обязан исключать локалхост, иначе редиректы со старого сайта
начнут отдавать 429.

### Расписание

Всё в `routes/console.php` — там же комментарии, почему что стоит именно так.
На бою его крутит systemd-таймер `intertooler-scheduler.timer`, **не cron**
(кронтабы пустые).

## Работа на деве

Сайт открывается **только** через nginx на **8103**, Vite слушает **5103**
(`strictPort`, порт зашит в `vite.config.js`). `php artisan serve` из `composer dev`
убран намеренно — не поднимать.

```bash
composer dev     # queue:listen + pail + vite, всё вместе
composer lint    # pint --parallel
composer test    # config:clear + pint --test + artisan test
```

### Кеш в Redis общий на все дев-проекты

Все проекты в `/home/edgar/dev` держат кеш в одной базе Redis (`REDIS_CACHE_DB=1`),
различаясь только префиксом ключей. **`php artisan cache:clear` и `optimize:clear`
на деве не звать:** у Redis-стора это `FLUSHDB`, и уходит кеш kratonshop, siteko
и остальных. Свой кеш чистить по префиксу:

```bash
redis-cli -n 1 --scan --pattern 'intertooler-dev-database-intertooler_dev_cache_*' \
    | xargs -r -d '\n' redis-cli -n 1 UNLINK
```

Префикс — `REDIS_PREFIX` (по умолчанию из `APP_NAME`) плюс `CACHE_PREFIX`.

### Тесты

Pest, sqlite `:memory:` (нужен пакет `php8.4-sqlite3`). В `phpunit.xml` намеренно
стоит `SCOUT_DRIVER=collection`: иначе тесты пишут документы в **живой Meilisearch
дева** — id у sqlite свои, и фикстура затирает документ настоящего товара с тем же
номером. Не менять.

Часть тестов падает на чистом `main` (зависят от данных/локали/внешних утилит).
Перед тем как считать падение регрессией — прогнать тесты на `main` и сравнить.
Параллельный прогон флапает из-за общего состояния, надёжный сигнал — последовательный.

### Composer

В `composer.json` есть приватный VCS-репозиторий `siteko/filament-restic-backups`
(`git@github.com:siteko-net/filament-restic-backups.git`). Доступ к нему даёт
SSH-ключ из проброшенного ssh-agent; **токена GitHub в `auth.json` нет**
(`github-oauth` пустой, проверено 2026-09-10).

Отсюда ловушка: увидев VCS-репозиторий на github.com, composer по умолчанию идёт
не клонировать, а в **REST API** (`use-github-api`, по умолчанию `true`) — быстрее,
но для приватного репозитория требует токен. Получается «Could not authenticate
against github.com», хотя ключ, которым можно склонировать, на месте. Лечится
переключением на git/SSH:

```bash
composer config --global use-github-api false
composer install
composer config --global --unset use-github-api
```

`--unset` в конце — настройка глобальная, а API заметно быстрее клонирования
для всех остальных проектов.

Вторая ловушка **сейчас не воспроизводится, но легко возвращается.** Если
`safe.bareRepository` выставлен в `explicit`, git отказывается работать с
bare-репозиториями, найденными автоматически. Composer кладёт VCS-зависимости
в кэш через `git clone --mirror`, то есть именно в bare, и падает с «cannot use
bare repository … safe.bareRepository is 'explicit'» → «No valid composer.json in
any branch or tag». На 2026-09-10 настройка не задана ни на одном уровне
(system/global/local/worktree), но если вернётся — обход на один вызов, без правки
глобального конфига:

```bash
GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.bareRepository GIT_CONFIG_VALUE_0=all \
GIT_SSH_COMMAND='ssh -o BatchMode=yes' composer install
```

Переменные окружения — потому что git здесь запускаешь не ты, а composer, своими
внутренними вызовами, куда флаг `-c` не подсунуть. `BatchMode=yes` не даёт ssh задать вопрос,
на который в неинтерактивном прогоне некому ответить (иначе — вечное ожидание
вместо ошибки).

Если ключей в агенте нет — заблокирована база KeePassXC на Windows. Попросить
разблокировать, а не генерировать ключ здесь: новый ключ в приватный репозиторий
всё равно не пустят.

### Прод-база на дев

Одна команда из корня репозитория:

```bash
./scripts/dev/pull-prod-db.sh                    # свежий дамп с прода → intertooler_dev
./scripts/dev/pull-prod-db.sh --from <.sql.gz>   # залить готовый дамп или откатиться
```

По порядку: дамп `stankoman` по ssh (структура целиком, данные без `cache`, `sessions`,
`jobs`, `failed_jobs`, `password_reset_tokens` и прочих эфемерных таблиц) → проверка,
что оба прохода дампа дошли до конца → снимок текущей дев-базы → снос всего в ней
и заливка → `migrate` текущей ветки → сброс кешей (только своих, см. выше) →
`products:search-reindex` → `search:audit` как проверка.

- Дампы — в `~/.local/share/intertooler/db-dumps/` (`prod-*` и `dev-*`, по 5 последних).
  Там персональные данные покупателей, в репозиторий и в `storage/` им нельзя.
- Откат — `--from` на `dev-<время>.sql.gz`; точная команда печатается в конце
  и при падении посреди заливки.
- Нужны ключи в ssh-agent (KeePassXC). Без `APP_ENV=local` в `.env` скрипт не запускается.
- После заливки таблица `sessions` пуста — из дева разлогинивает. В админку пускает
  прод-настройка `general.filament_admin_emails`, а не `.env`.

Почему скрипт устроен именно так — ловушки, все встречались:

1. пользователь существует только как `stankoman`@`127.0.0.1` — всегда `-h127.0.0.1 -P3306`,
   подключение по умолчанию идёт через сокет и даёт `Access denied`;
2. пароль содержит `@` и `:` — не передавать вложенными кавычками в `ssh host '...'`,
   слать скрипт через `ssh host 'bash -s' < script` и `export MYSQL_PWD=`;
3. `information_schema.table_rows` для InnoDB — оценка, а не счёт. По ней нельзя
   делать выводы о расхождении схем;
4. при импорте нужен `` sed -E 's/DEFINER=`[^`]*`@`[^`]*`//g' ``, иначе VIEW
   `attribute_product_links` не создастся без SUPER;
5. локальный шелл — zsh: `MY="mariadb -u..."` и потом `$MY` в позиции команды
   не разворачивается (нет word splitting). Отсюда bash-скрипт с функциями.

## Деплой

**Собранные ассеты едут через git.** На бою нет ни node, ни npm, ни vite —
`post-receive` только *проверяет*, что в архиве есть `public/build/manifest.json`,
и иначе отказывает с «Build frontend assets before pushing to deploy remote».

Правильный путь — обёртка, которая делает всё по порядку:

```bash
./scripts/deploy/prod-deploy.sh [remote=prod] [branch=main]
```

Она собирает `npm run build` + `npm run build:rich-content-plugins`, при изменениях
в `resources/js/dist` зовёт `php artisan filament:assets`, проверяет манифест,
коммитит `public/build resources/js/dist public/js` и пушит.

Дальше серверный хук сам делает `git archive` в `releases/<timestamp>`, симлинки на
общие `.env` и `storage`, `composer install --no-dev`, миграции (с `artisan down`
только если миграции есть), `optimize`, переключает `current` и перезапускает
очередь/планировщик/php-fpm. Хранит 5 релизов.

Пуш в `origin` (GitHub) деплой **не** запускает.

`vite.config.js` обязан быть в git. Он однажды был в `.gitignore`, пропал с машины,
и сборка начала падать с `Could not resolve entry module "index.html"` — восстановить
из репозитория было нечем.

## Боевой сервер

Ubuntu 24.04, 2 vCPU / ~3.7 ГБ RAM. Коробка общая — на ней же kratonkuban.ru.
Приложение в `/srv/www/intertooler` (`current` → `releases/<ts>`, `shared/`, `repo.git`).

- nginx 1.24 + php8.4-fpm, сертификаты через certbot;
- MariaDB 11.4, root по unix_socket (`sudo mariadb`);
- Redis на 6379, Meilisearch в докере на `127.0.0.1:7700`
  (`/opt/meilisearch/docker-compose.yml`);
- очередь — systemd-юнит `intertooler-queue-default.service` (после обновления PHP
  его надо перезапустить, иначе держит старый бинарник);
- бэкапы — Filament-плагин restic, команды `restic-backups:*`;
- ispmanager на 1500/1501, ufw, fail2ban.

Сторонние apt-репозитории (docker, sury, mariadb.org) не показываются как
ubuntu-security — `apt list --upgradable` смотреть руками.

`ssh.service` в состоянии «inactive (dead)» — это норма, там сокет-активация,
проверять надо `ssh.socket`.

**Логи nginx читаются только под sudo, а беспарольный sudo есть у `edgar`, не у `deploy`.**

### Когда сайт «лёг»

Оба всплеска нагрузки (03 и 06.09.2026) оказались краулерами, а не своим кодом.
Начинать с `/var/log/nginx/access.log` (разбивка по минутам и по User-Agent) и
`/var/log/php8.4-fpm.log` (`reached pm.max_children`, `executing too slow`).

Что уже стоит на бою и как это ставилось — [`scripts/deploy/nginx/README.md`](scripts/deploy/nginx/README.md).
Две грабли оттуда:

1. `/index.php/что-угодно` отдавалось обычной страницей — это и вторая копия каталога
   для поисковиков, и обход правил nginx, описанных от корня. Правила во вхосте
   писать так, чтобы префикс их не обходил.
2. В `geo` значения — литералы, переменные не раскрываются. `default $binary_remote_addr`
   давал всем клиентам один общий бакет на весь сайт. Лимит проверять обязательно
   с двух источников: тест с одного проходит и на сломанном конфиге.

## Соглашения

### Коммиты

Conventional Commits с **русской** темой, которая описывает результат для человека,
а не механику правки:

```
feat(search): раздел, наличие и популярность товара доезжают до индекса
fix(search): товар не попадает на сайт мимо поискового индекса
fix(nginx): лимит частоты снова считается по адресу, а не на всех сразу
```

Не «добавил метод в сервис», а что изменилось на сайте.

### Комментарии в коде

В проекте принято объяснять **почему**, и по-русски — см. `routes/console.php`,
`phpunit.xml`, `vite.config.js`. Такие комментарии не вычищать: они держат решения,
за которые уже платили инцидентами.
