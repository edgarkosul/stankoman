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

### Тесты

Pest, sqlite `:memory:` (нужен пакет `php8.4-sqlite3`). В `phpunit.xml` намеренно
стоит `SCOUT_DRIVER=collection`: иначе тесты пишут документы в **живой Meilisearch
дева** — id у sqlite свои, и фикстура затирает документ настоящего товара с тем же
номером. Не менять.

Часть тестов падает на чистом `main` (зависят от данных/локали/внешних утилит).
Перед тем как считать падение регрессией — прогнать тесты на `main` и сравнить.
Параллельный прогон флапает из-за общего состояния, надёжный сигнал — последовательный.

### Composer

В `composer.json` есть приватный VCS-репозиторий `siteko/filament-restic-backups`.
Обычный `composer install/update` здесь падает по двум причинам, обе обходятся
без правки глобальных настроек насовсем:

1. валидного GitHub-токена в auth.json нет → composer идёт через API и получает
   «Could not authenticate against github.com». Лечится
   `composer config --global use-github-api false` (потом `--unset`);
2. глобально стоит `safe.bareRepository=explicit`, что блокирует `--mirror`-клоны.
   Обход на один вызов:

```bash
GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.bareRepository GIT_CONFIG_VALUE_0=all \
GIT_SSH_COMMAND='ssh -o BatchMode=yes' composer install
```

Ключ для доступа — в проброшенном ssh-agent (KeePassXC на Windows). Если ключа нет,
база заблокирована — попросить разблокировать, а не генерировать ключ здесь.

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

### Прод-база на дев

Дамп `stankoman` → локальный `intertooler_dev`. Три ловушки, все встречались:

1. пользователь существует только как `stankoman`@`127.0.0.1` — всегда `-h127.0.0.1 -P3306`,
   подключение по умолчанию идёт через сокет и даёт `Access denied`;
2. пароль содержит `@` и `:` — не передавать вложенными кавычками в `ssh host '...'`,
   слать скрипт через `ssh host 'bash -s' < script` и `export MYSQL_PWD=`;
3. `information_schema.table_rows` для InnoDB — оценка, а не счёт. По ней нельзя
   делать выводы о расхождении схем.

При импорте нужен `sed -E 's/DEFINER=`[^`]*`@`[^`]*`//g'`, иначе VIEW
`attribute_product_links` не создастся без SUPER.

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
