# CLAUDE.md — intertooler

Интернет-магазин промышленного оборудования (станки, инструмент, оснастка) —
[intertooler.ru](https://intertooler.ru). Витрина и админка живут в одном
Laravel-приложении, каталог наполняется импортом от поставщиков.

> **Общие правила кода** (Laravel/Filament/Livewire/Pest/Tailwind, PHP-стиль,
> запуск Pint и тестов) лежат в [`.github/copilot-instructions.md`](.github/copilot-instructions.md) —
> это файл, который генерирует Laravel Boost. Здесь его не дублируем; здесь то,
> что специфично для этого проекта и из кода не выводится.

## Стек

Laravel 12 + Livewire 4 (Flux free) + Filament 5, PHP 8.4, MariaDB, Redis
(кэш и очередь), Meilisearch 1.16 через Scout, Tailwind v4 (CSS-first, без
`tailwind.config.js`), Vite 7, Pest 4. Аутентификация витрины — Fortify,
печать карточки товара — Dompdf, импорт/экспорт таблиц — PhpSpreadsheet,
подсказки по реквизитам — DaData.

## Наследие в именах

Проект вырос из старого сайта stankoman.ru, поэтому вокруг много «чужих» имён —
это не ошибка и переименовывать не надо:

- git-remote `origin` → `git@github.com:edgarkosul/stankoman.git`;
- боевая БД называется `stankoman`, индекс Meilisearch — `stankoman_products`;
- домен stankoman.ru живым DNS больше не отвечает, это просто редирект на intertooler;
- в каталоге есть слой legacy-товаров kraton (см. ниже).

Отдельная ловушка: `config/filament-help.php` — сорок с лишним ссылок «Помощь»
из админки — ведёт на **help.stankoman.ru**, а этот домен не резолвится
(проверено 10.09.2026), сертификат удалён, nginx-блок отключён. Это
**нереализованная идея, а не сломанная справка** (подтверждено 14.09.2026): статей
нет, писать их некуда. Ссылки не «чинить» и новым разделам админки не заводить —
новый маршрут вносится в список исключений `tests/Unit/FilamentHelpCenterTest.php`,
иначе этот тест упадёт.

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

Заказ оформляется одним входом: `OrderPlacementService::submit()` пишет всё в
транзакции и бросает `OrderSubmitted`, письма шлёт листенер. Номер заказа —
дата плюс порядковый (`01-02-26/03`), он же разложен на два сегмента в адресах
`/checkout/success/{date}/{seq}` и `/user/orders/{date}/{seq}`.

**Два кэша меню**, и оба легко забыть:

- дерево категорий для шапки — вью-композер в `AppServiceProvider`,
  ключ `Category::CATALOG_MENU_CACHE_KEY`, 30 минут;
- редактируемое меню — `MenuService::tree()`, `rememberForever`.

Правка категории или пункта меню, не сбросившая кэш, на сайте просто не видна.
Новый маршрут в селекте пунктов меню не появится сам — его надо добавить в
`config/menu.php` (`allowed_routes`).

### Кто куда пускается

Ролей нет: доступ в Filament даёт `User::canAccessPanel()` по списку e-mail
(`settings.general.filament_admin_emails`). Обратная сторона — `canUseStorefront()`:
для админа и менеджера витрина закрыта, `EnsureStorefrontCustomer` уводит их
с `/checkout`, `/user/*` и `/settings/*` на главную. Это **так задумано** —
у гостей и обычных покупателей оформление работает.

**Осторожно с окружением:** на бою `FILAMENT_ADMIN_EMAILS` и `SHOP_MANAGER_EMAILS`
заданы на уровне ОС/php-fpm и **перебивают `.env`** (phpdotenv не переопределяет уже
установленную переменную). Проверено 2026-06-26: `.env` обещает `admin@siteko.net`,
а живой конфиг отдаёт `sales@intertooler.ru` + `r_kodachenko@mail.ru`. Если доступы
ведут себя «не как в .env» — смотреть `grep -r FILAMENT_ADMIN_EMAILS /etc/php /etc/systemd`,
а не только `.env`. В деве это не воспроизводится: там переменные пустые.

### Настройки из БД перебивают конфиг

`SettingsServiceProvider::boot()` читает автозагружаемые строки таблицы `settings`
(кэш `rememberForever`, ключ `Setting::CACHE_KEY`) и раскладывает их по конфигу:
`company.*` и `mail.*` — по своему пути, всё остальное — под `settings.<key>`.
Пустые значения для `general.manager_emails`, `general.filament_admin_emails`,
`company.public_email` и `mail.from.address` намеренно игнорируются, чтобы пустая
настройка не затёрла конфиг.

Отсюда два следствия: рантайм может отличаться от `config/*.php` — смотреть надо
админку и БД; правка настройки мимо админки требует сброса кэша.

### Каталог, цены, скидки

- `price_amount` и `discount_price` — целые **рубли**, не копейки (в отличие от
  siteko). Формат для вывода — хелпер `price()` из `app/Support/helpers.php`.
- Оптовая цена ведётся в валюте: `wholesale_price` + `wholesale_currency` +
  `exchange_rate` → `wholesale_price_rub`, `markup_multiplier`, `margin_amount_rub`.
  Курсы тянет `products:sync-currency-rates` с ЦБ (`cbr.ru/scripts/XML_daily.asp`)
  в 00:00, кладёт в `settings` и пересчитывает товары с `auto_update_exchange_rate`.
- **Скидка видна только авторизованным** (`DiscountVisibility`, решение заказчика
  от 20.08.2026): гостю в корзине и в фиде — базовая цена, на карточке скидочная
  показывается как цена «после регистрации», в чекауте она включается, если человек
  ставит галку «завести личный кабинет».
- Картинки: оригиналы в `public/pics` (вне git), webp-производные по лестнице ширин
  делает `ImageDerivativesGenerator`, отдаёт `ImageDerivativesResolver` (srcset).
  Ночью `images:webp-backfill --limit=500` добивает то, что не сгенерилось на лету.

### Поиск по каталогу

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
поля доедут до Meilisearch только руками. И само поле мало объявить в
`config/scout.php`: если его нет в `Product::toSearchableArray()`, фильтр и
сортировка молча не работают (так уже было с `in_stock` и `popularity`).

`scout.after_commit` обязан оставаться `true`: при импорте товар создаётся одним
запросом, а к категории цепляется следующим — документ, собранный до коммита,
уезжает с пустым `category_ids`.

### ИИ-ассистент: шов к магазину

Сервисы бота (`app/Services/{Ai,Kb,Chat,Catalog}`) **не знают моделей магазина**.
Товар, каталог и цены приходят к ним контрактом
`App\Services\Ai\Contracts\ProductLookup`, а про `Product` и `Category` знает
только `App\Shop`. Правило держит тест `tests/Unit/AiServicesSeamTest.php`:
`use App\Models\…` в сервисах его роняет. Причина не в чистоте — тот же бот
ставится во втором магазине заказчика, где ценовая политика другая, и код,
читающий модель напрямую, переезжает вместе с чужим правилом.

**Цена в чате — то же число, что на карточке.** `ProductCard` несёт ровно одну
цену — ту, что видит этот посетитель, — и сумму скидки для зарегистрированных
гостю не отдаёт даже полем: собрать её из карточки нечем. Меняя ценовую
политику витрины (`DiscountVisibility`, блок цены в `summary.blade.php`),
править надо и `App\Shop\EloquentProductLookup::price()`: это два места одного
правила, и расхождение покупатель прочтёт как обман. Третий слой — выходная
проверка в `ReplyFormatter`: предложение с ценой, которой посетитель на витрине
не видит, выбрасывается из ответа и пишется в лог.

Поиск товаров по словам — одна точка входа `App\Support\Search\ProductTextSearch`
на витрину и на бота. В ней три правила: латиница (`LatinQuery`), написание бренда
вместо его прочтения (`BrandSpelling`, «сталекс» → Stalex) и повтор пустого запроса
без слов, которых нет в каталоге (`QueryRelaxation`: «бензогенератор tehnotek» давал 0
при 78 товарах бренда). Своих копий этих правил не заводить: у донора разошлись ровно
две такие копии, и поиск в шапке находил семь компрессоров Hansmann, а бот на тот же
запрос отвечал «не нашлось». Справочник брендов бота (`CatalogBrands`) сверяет
звучание той же `BrandSpelling::fold()` — иначе бренд, найденный поиском, получил бы
фильтр по типу техники и потерял выдачу.

### Чат на витрине

Лаунчер `x-support.chat-launcher` стоит в лэйауте витрины; до клика это Blade и Alpine
без единого запроса, панель `support.chat-panel` грузится `lazy` из скрытого контейнера.
Посетителя опознаёт только httpOnly-кука `intertooler_chat` с токеном разговора — id
от клиента не принимаются нигде. Ответ готовит `GenerateChatReplyJob` на `redis-assistant`:
без запущенного воркера (`composer dev`, процесс `assistant`) панель «печатает» 210 секунд
и сдаётся заглушкой — это первое, что проверять, когда чат «завис».

- **Чат тоже за швом.** `app/Services/Chat` моделей магазина не знает (сторож тот же,
  `AiServicesSeamTest`): страницу и цену на ней отдаёт `App\Shop\ShopPageContext` через
  `ProductLookup`, форму контактов — `App\Shop\CallbackLeadIntake` (заявка «перезвоните»
  с `source=chat`). Своей формулы цены для контекста страницы не заводить.
- **Предпросмотр** — `AI_CHAT_PREVIEW=true` + `AI_CHAT_PREVIEW_KEY`: виджет видят
  сотрудники (`isFilamentAdmin()`) и те, кто открыл сайт с `?bot=<ключ>`. Пустой ключ
  при включённом предпросмотре — «никому», а не «всем».
- **«Менеджер на связи»** — `OperatorPresence` по режиму работы `company.work_schedule`,
  тому же, что в шапке. Перебить его вручную можно переключателем «я на смене»
  в топбаре админки (`App\Livewire\Admin\OperatorPresenceToggle`, хук `USER_MENU_BEFORE`);
  ручное решение живёт не дольше `operators.override_ttl_minutes` — переключатель
  без срока годности забывают включённым.
- **Эскалация зовёт человека тремя каналами** (`NotifyManagersAboutEscalationJob`
  на очереди `default`, не на ассистентской): уведомление в колокольчике админки —
  источник истины, плюс письмо менеджерам и пуш в MAX, оба best-effort. Один сигнал
  о разговоре в `escalation.notify_cooldown_minutes` (час), и только оставленные
  контакты идут мимо кулдауна — это уже не сигнал, а задача. Вне смены пуш в MAX
  откладывается до её начала: ночью он разбудит и ничего не изменит.
  ⚠️ **Тихая авария:** почта в `general.filament_admin_emails` без строки в `users`
  получателем DB-уведомления не станет, и ошибки не будет нигде. Проверяет
  `ai:kb-doctor` разделом «Эскалация» — на 15.09.2026 из двух прод-почт пользователь
  есть только у `r_kodachenko@mail.ru`.
- **Кто такой сотрудник — тоже шов**: `App\Services\Chat\Contracts\EscalationTarget` →
  `App\Shop\SettingsEscalationTarget`. Список почт панели он берёт у
  `User::filamentAdminEmails()`, а не собирает из конфига заново: «кому показывать
  уведомление» обязано совпадать с «кого пускать в админку».
- **Ответ менеджера в закрытую вкладку** — письмо `ChatOperatorRepliedMail` с подписанной
  ссылкой `chat.resume` (неделя). Уходит только вошедшему в аккаунт (у анонима почты нет),
  только если его нет в чате прямо сейчас, и не чаще раза в час.
- `/session/keepalive` и `resources/js/modules/livewire-session-guard.js` действуют на весь
  сайт: вместо английского «This page has expired» Livewire молча получает свежий токен.
- Переписка обезличивается через 30 дней и удаляется через 180 (`chat:purge`, 04:15);
  расходная книга `ai_usage_entries` при этом остаётся.

### Рабочее место бота в админке

Группа **«ИИ бот»**: «Диалоги» (переписка и ответ оператора), «Пробелы» (очередь
работ базы знаний), «Статьи для бота», «Разделы статей», «Спросить бота»
(песочница), «Настройки бота».

⚠️ **У группы есть значок — значит у её пунктов иконок быть не может.** Filament
разрешает иконку либо группе, либо пунктам, и ловит нарушение исключением прямо
в шаблоне сайдбара: падает вся админка, а не один раздел.

- **Переписку ведёт `App\Livewire\Admin\ChatConversationPanel`** (опрос 8 с), а не
  страница Filament: лента обновляется сама, не перерисовывая страницу. Бейдж
  у «Диалогов» (`ChatConversation::scopeAwaitingStaff`) — пока единственный сигнал
  «покупатель ждёт человека»: уведомлений менеджерам ещё нет.
- **Четыре сигнала «Пробелов»**: 👎, «бот позвал человека», «не нашёл в базе» —
  их оставляет бот; четвёртый ставит человек кнопкой «В базу знаний» у своей
  реплики. Группировка идёт по вектору ВОПРОСА: у ответов бота он посчитан джобой,
  у операторской реплики считается в момент пометки.
- **Три места зовут модель синхронно, прямо в FPM**: черновик ответа оператору,
  черновик статьи на «Пробелах» и песочница. Это осознанное исключение из правила
  «LLM не в PHP-FPM»: там его ждёт покупатель и воркеров мало, здесь кнопку жмёт
  один сотрудник и смотрит на кружок. Везде поднят `set_time_limit`.
- **Настройки бота пишутся в `settings` под префиксом `assistant.`**, и пишет их
  страница, а не `AssistantConfig`: сервисы ассистента моделей магазина (включая
  `Setting`) не знают — это тот же шов и тот же сторож `AiServicesSeamTest`.
  Своего расписания у бота нет: режим работы один — `company.work_schedule`.
- **`TrackOperatorActivity` стоит в `authMiddleware`,** а не в общем списке
  посредников панели: иначе «менеджер на смене» включал бы любой заход
  на `/admin/login`, в том числе краулерский.
- **Тема админки** (`resources/css/filament/admin/theme.css`) знает про
  `app/Livewire/Admin` и `resources/views/livewire/admin` и несёт стили `chat-md` —
  без них ответ бота в ленте слипается в один абзац. Утилит `dark:` в новых
  шаблонах не заводить: тема светлая, сторож — `tests/Unit/LightThemeOnlyTest.php`.

### Импорт товаров

Самая большая подсистема. Слои:

- команда-вход `catalog:import-products {supplier} [--queue --write --mode=...]`,
  где supplier — `vactool`, `metalmaster` или `yandex_market_feed`;
- `app/Support/CatalogImport/` — общий каркас: `Drivers/` (HTML для vactool и
  metalmaster, XML для metaltec, YML для stalex и Яндекс-фида), `Suppliers/`
  (адаптеры и профили), `Processing/`, `Media/`, `Runs/` (оркестратор и журнал);
  парсеры конкретных сайтов — в `app/Support/{Vactool,Metalmaster,Metaltec}/`;
- `app/Jobs/Run*ImportJob.php` — прогоны в очереди. Metaltec и Stalex своей ветки
  в команде не имеют, у них только джобы;
- `config/catalog-import.php` — ключи `schedule` (в расписании стоят только vactool
  и metalmaster), `media`, `feed_upload`;
- модели `ImportRun`, `ImportRunEvent`, `ImportIssue`, `ImportMediaIssue`,
  `ImportFeedSource`, `SupplierImportSource` — журнал прогонов, он же виден в админке.

Без `--write` это dry-run: команда показывает план изменений и ничего не пишет.

Обратная сторона импорта — он пишет запросами, мимо событий модели. Любое такое
место обязано само позвать `ProductSearchSync` (см. выше) и, если правит цены,
пересчитать производные поля.

### Фиды, SEO и печать

- `/market.xml` отдаёт **готовый файл** `storage/app/public/feeds/yandex-market.xml`,
  который ночью в 04:40 пишет `feeds:generate-market`. Маршрут ничего не считает.
- `seo:generate-sitemap` в 04:30 кладёт `public/sitemap*.xml` и `robots.txt` — они
  в `.gitignore` и генерируются на месте, в репозитории их нет.
- SEO-теги страниц собирает `SiteSeoDataBuilder` через вью-композер лэйаута:
  контроллер передаёт массив `seo` во вью, остальное достраивается само.
- `/product/{slug}/print` строит PDF **синхронно** и занимает php-fpm воркер на
  секунды, поэтому у маршрута `throttle:12,1`, а на бою ещё и правила nginx против
  ботов (см. «Когда сайт „лёг“»).

### Админка

Filament 5, ресурсы в `app/Filament/Resources/` (Products, Categories, Orders,
Attributes, Menus, Pages, Settings, Sliders, Units, Users, ImportRuns,
LegacyProducts) плюс отдельные страницы под импорт/экспорт в `app/Filament/Pages/`.
Панель — `app/Providers/Filament/AdminPanelProvider.php`: тема через
`viteTheme()`, тёмный режим выключен, группы навигации свёрнуты, часовой пояс
`Europe/Moscow`, плагин бэкапов `siteko/filament-restic-backups` подключается
только если класс есть (чтобы не падать во время `composer update`).

### Legacy kraton

Со старого сайта kratonkuban.ru идут редиректы. Резолвер —
`/_legacy/kraton/resolve` (`LegacyKratonRedirectController`), сопоставление товаров
ночью — `legacy:kraton-match`, настройки (домен-источник, код ответа, разрешённые
стратегии сопоставления) — `config/legacy.php`. План миграции:
[`docs/legacy-kraton-redirect-plan.md`](docs/legacy-kraton-redirect-plan.md).

**Важно для nginx:** резолвер ходит с `127.0.0.1` и даёт до 212 запросов в минуту.
Любой `limit_req` по IP обязан исключать локалхост, иначе редиректы со старого сайта
начнут отдавать 429.

### Расписание

Всё в `routes/console.php` — там же комментарии, почему что стоит именно так.
На бою его крутит systemd-таймер `intertooler-scheduler.timer`, **не cron**
(кронтабы пустые). Очередь на бою обрабатывает один воркер —
`intertooler-queue-default.service`.

## Работа на деве

Сайт открывается **только** через nginx на **8103**, Vite слушает **5103**
(`strictPort`, порт зашит в `vite.config.js`). `php artisan serve` из `composer dev`
убран намеренно — не поднимать.

```bash
composer dev     # queue:listen + pail + vite, всё вместе
composer lint    # pint --parallel
composer test    # config:clear + pint --test + artisan test
```

Локальное окружение: MariaDB `intertooler_dev`, Redis под кэш и очередь (как на
бою; сессии в деве — в БД), Meilisearch на 7703, почта в mailpit на 1025.
В `local`/`testing` доступны превью писем: `/_preview/mail`.

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

Так же намеренно там стоит `AI_EMBEDDING_FAKE=true`: обычный прогон идёт на заглушке
без сети — вектор из хэша детерминирован, тесты на близости не мигают и не ждут шлюз.
Настоящий шлюз — отдельной группой `php artisan test --group=live` (ключ `AI_GATEWAY_KEY`
из `.env`, копейки за прогон), в обычный прогон она не входит. Тест, который может
дёрнуть шлюз мимо заглушки (например `ai:kb-doctor` спрашивает ключ), обязан
подменить HTTP. И ещё одна ловушка ассистента: джобы на
соединении `redis-assistant` (например `ReindexKbDocumentJob`, её ставят наблюдатели
страниц из белого списка базы знаний и статей `KbArticle`) идут в Redis **мимо**
`QUEUE_CONNECTION=sync`. Тест, который сохраняет такую страницу или статью, обязан
звать `Queue::fake()`, иначе задача уедет в живую очередь дева.

`tests/Pest.php` перед каждым тестом делает три вещи: проверяет, что база
безопасная (sqlite либо имя с `_test` — иначе бросает и не даёт снести дев-базу),
пересоздаёт таблицы категорий (unit-тесты делят одну память, пивот от прошлого
файла прицепился бы к чужому товару) и создаёт таблицы плагина бэкапов, которых
нет в миграциях приложения.

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

Дальше серверный хук (`/srv/www/intertooler/repo.git/hooks/post-receive`) сам делает
`git archive` в `releases/<timestamp>`, симлинки на общие `.env` и `storage`,
`composer install --no-dev --optimize-autoloader --no-scripts`, `storage:link`,
`optimize:clear`, миграции (с `artisan down --secret` только если миграции есть),
`optimize --except=views`, права, переключает `current` и делает `schedule:interrupt`
+ `queue:restart` + reload php8.4-fpm. Хранит 5 релизов.

Пуш в `origin` (GitHub) деплой **не** запускает.

`vite.config.js` обязан быть в git. Он однажды был в `.gitignore`, пропал с машины,
и сборка начала падать с `Could not resolve entry module "index.html"` — восстановить
из репозитория было нечем.

**Пушит и деплоит пользователь, не агент.** Закончив работу, коммить и отдавать
короткую инструкцию: что запустить после деплоя, если нужны шаги сверх хука.

## Боевой сервер

Ubuntu 24.04, 2 vCPU / ~3.7 ГБ RAM / 59 ГБ. Коробка общая — на ней же kratonkuban.ru
(+ поддомены files/manual) и мёртвый stankoman.ru. Приложение в
`/srv/www/intertooler` (`current` → `releases/<ts>`, `shared/`, `repo.git`),
сайты ispmanager — в `/var/www`.

### Доступ

- `ssh intertooler-production` → хост `188.120.237.93`, пользователь **deploy**,
  попадаешь в `/srv/www/intertooler/current`;
- `ssh edgar@intertooler-production` → тот же хост под **edgar**;
- **беспарольный sudo есть у `edgar`, не у `deploy`.** Для root по
  неинтерактивному ssh: `ssh edgar@intertooler-production 'sudo bash -s'`.
  Логи nginx читаются только под sudo — то есть под `edgar`;
- имя машины в приглашении — `seteko-production`, это не опечатка и не другой хост;
  `intertooler-production` — просто алиас из `~/.ssh/config`;
- ключ отдаёт **KeePassXC на Windows** через проброшенный ssh-agent. Нет ключей
  в `ssh-add -l` или «Permission denied (publickey)» — почти всегда заблокирована
  база KeePassXC, а не упал сервер. Свой ключ здесь не генерировать.

### Что где

- **Web**: nginx 1.24 (вхосты в `/etc/nginx/sites-available` + симлинки в
  `sites-enabled`, сертификаты certbot) → php8.4-fpm.
- **БД**: MariaDB 11.4 (репозиторий mariadb.org), localhost:3306, root по
  unix_socket (`sudo mariadb`). База приложения называется `stankoman`.
- **Кэш/очередь/сессии**: Redis на 6379.
- **Поиск**: Meilisearch в докере, compose — `/opt/meilisearch/docker-compose.yml`,
  контейнер `meilisearch-meilisearch-1` (`restart=unless-stopped`), слушает
  `127.0.0.1:7700`, здоровье — `curl 127.0.0.1:7700/health`.
- **Очередь**: systemd-юнит `intertooler-queue-default.service` (один `queue:work redis`).
  После обновления PHP его надо перезапустить, иначе держит старый бинарник.
- **Планировщик**: `intertooler-scheduler.timer` → `schedule:run` каждую минуту.
- **Бэкапы**: Filament-плагин restic, команды `restic-backups:{run,cleanup-rollbacks,cleanup-exports,unlock}`.
- **Прочее**: ispmanager на 1500/1501, vsftpd на 21 (пассивные 40000–40100),
  privoxy 8118 (локально), ufw, fail2ban (sshd, vsftpd), unattended-upgrades.

Сторонние apt-репозитории (docker, sury, mariadb.org) не показываются как
ubuntu-security — `apt list --upgradable` смотреть руками.

### Грабли этой машины

- **`ssh.service` в состоянии «inactive (dead)» — это норма**: там сокет-активация,
  проверять надо `ssh.socket` и `ss -ltn | grep :22`.
- **UFW не открывает 22 всем** — только конкретным админским адресам. Адрес
  дев-машины — **139.100.225.13** (её реальный IP на eth0). `185.239.142.147` —
  это транзитный HTTP-прокси хостера, и web-сервисы «какой у меня IP» показывают
  именно его, то есть врут для целей allowlist. Источник смотреть по `$SSH_CLIENT`
  или `ip -4 addr show eth0`, а на самой цели — `tcpdump dst port 22` без фильтра
  по адресу. В июле 2026 неверно угаданный адрес стоил дня разбирательств и
  напрасной претензии хостеру.
- **Приложение переехало с `/var/www/intertooler` на `/srv/www/intertooler`**, и
  часть конфигов осталась со старым путём. Мёртвый путь не ломается на глаз — он
  падает только когда до него доходит дело (ночной бэкап, продление сертификата).
  Двое уже найдены и починены 2026-06-23 (`project_root` плагина restic в БД и
  `webroot_path` в `/etc/letsencrypt/renewal/intertooler.ru.conf`), остальные —
  `grep -r /var/www/intertooler` при любой «внезапно перестало работать».
- **certbot.service падал из-за stankoman.ru**: зона мертва в публичном DNS
  (`SERVFAIL`, делегирования нет), сервер резолвит её только строкой в `/etc/hosts`.
  Сертификаты stankoman.ru и help.stankoman.ru удалены 2026-06-23, блок help
  отключён. Если certbot снова красный — сначала посмотреть, не чужой ли это домен.

### Когда сайт «лёг»

Оба всплеска нагрузки (03 и 06.09.2026) оказались краулерами, а не своим кодом.
Начинать с `/var/log/nginx/access.log` (разбивка по минутам и по User-Agent) и
`/var/log/php8.4-fpm.log` (`reached pm.max_children`, `executing too slow`).

Что уже стоит на бою и как это ставилось — [`scripts/deploy/nginx/README.md`](scripts/deploy/nginx/README.md):
карта плохих ботов для `/print`, карта и зоны `limit_req` в `/etc/nginx/conf.d/`,
плюс несколько `location` во вхосте `intertooler.ru`.

Три грабли оттуда:

1. `/index.php/что-угодно` отдавалось обычной страницей — это и вторая копия каталога
   для поисковиков, и обход правил nginx, описанных от корня (через него боты
   сгенерировали 1145 PDF за сутки против 65 заблокированных). Правила во вхосте
   писать так, чтобы префикс их не обходил.
2. В `geo` значения — литералы, переменные не раскрываются. `default $binary_remote_addr`
   давал всем клиентам один общий бакет на весь сайт: 08.09.2026 скан WP-путей
   выбрал его, и 429 получили посетители из Директа, YandexBot и чекер мониторинга.
   Починено в `fdfc55b` (`geo` отдаёт флаг, ключ собирает `map`). Лимит проверять
   обязательно с двух источников: тест с одного проходит и на сломанном конфиге.
3. `127.0.0.1` — не служебный шум, оттуда ходит резолвер legacy-редиректов
   kratonkuban.ru. Живые посетители дают не больше ~40 запросов в минуту.

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
