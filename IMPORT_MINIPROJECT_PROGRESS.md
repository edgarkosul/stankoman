# Прогресс реализации: Универсальный импорт товаров

Связанный план: `IMPORT_MINIPROJECT_PLAN.md`

## Статусы этапов
- [x] Этап 0. Discovery и фиксация текущего состояния
- [x] Этап 1. Контракты и DTO
- [x] Этап 2. Import Run и логирование
- [x] Этап 3. Источники и парсеры форматов
- [x] Этап 4. Нормализация и upsert
- [ ] Этап 5. Медиа-пайплайн
- [ ] Этап 6. Адаптеры поставщиков
- [ ] Этап 7. Точки запуска и эксплуатация
- [ ] Этап 8. Тестирование и приемка

## Хронология изменений
### 2026-03-03
- Создан базовый план минипроекта в `IMPORT_MINIPROJECT_PLAN.md`.
- Инициализирован этот файл для фиксации прогресса.

### 2026-03-05
- Зафиксированы принятые решения по режимам прогона, политике “missing”, категориям и минимальному `ProductPayload`.
- Зафиксирован подход к YML (Yandex Market Language): потоковый парсинг, strict adapter, поддержка двух типов offer.
- Начата реализация YML-импорта: добавлены `YmlStreamParser` + `YandexMarketFeedAdapter` + базовые DTO и unit-тесты.
- Изменения по YML (парсер/адаптер/DTO/тесты) закоммичены.
- Выполнен Этап 0 (discovery): описаны текущие импорты Metalmaster/Vactool, текущие точки входа, обязательные поля `Product`, список рисков.
- Выполнен Этап 1 (контракты и DTO): добавлены интерфейсы `SourceResolverInterface`, `RecordParserInterface`, `SupplierAdapterInterface`, `ImportProcessorInterface`.
- Добавлены DTO/enum-контракты `ResolvedSource`, `ImportError`, `RecordMappingResult`, `ImportProcessResult`, `ImportErrorLevel`, `ImportRunStatus`.
- `YmlStreamParser` и `YandexMarketFeedAdapter` переведены на новые контракты; удалены устаревшие `AdapterIssue`/`AdapterResult`.
- Добавлены/обновлены unit-тесты (`CatalogImportContractsTest`, `YmlStreamParserTest`) для проверки Этапа 1.
- Выполнен Этап 2 (Import Run и логирование): добавлен общий сервис оркестрации `ImportRunOrchestrator` (start/progress/success/fail/cancel + mergeMeta + threshold-check).
- Existing Vactool/Metalmaster интегрированы с новым run-orchestration без смены legacy-статусов (`pending`/`dry_run`/`applied`/`failed`/`cancelled`), что сохраняет текущую работу UI и job-ов.
- В `RunVactoolProductImportJob` и `RunMetalmasterProductImportJob` убрано дублирование lifecycle-логики, добавлен опциональный порог остановки по ошибкам (`error_threshold_count` / `error_threshold_percent`) с issue-кодом `error_threshold_exceeded`.
- Обновлены поддерживаемые статусы для нового import core: `ImportRunStatus` расширен (legacy + `running`/`completed`), `ImportRunObserver` и `ImportRunsTable` теперь понимают `completed`.
- Добавлены/обновлены тесты: `ImportRunOrchestratorTest`, `RunVactoolProductImportJobTest` (включая threshold case), `RunMetalmasterProductImportJobTest`, `ImportRunDatabaseNotificationTest`.
- Зафиксирована стратегия полного перехода legacy-парсеров:
  - перенос сбора сырого контента в `RecordParserInterface` + `SupplierAdapterInterface`;
  - сохранение текущего shape прогресса/результата (`processed/errors/created/updated/skipped/fatal_error/url_errors/samples/no_urls`) до полной миграции UI;
  - переключение run-статусов на `running`/`completed` только после parity и подтверждения тестами.
- Выполнен Этап 3 (источники и парсеры форматов):
  - реализован `SourceResolver` (`SourceResolverInterface`) с поддержкой локальных файлов и URL-источников (`timeout`/`connect_timeout`/`retry`) и условных заголовков `If-None-Match`/`If-Modified-Since` (ETag/Last-Modified) через файловый cache;
  - реализованы DTO форматного слоя `XmlRecord` и `HtmlRecord`;
  - реализован `XmlStreamParser` (`RecordParserInterface`) на `XMLReader` с выбором повторяющегося узла (`record_node`) и конвертацией XML-записей в UTF-8;
  - реализован базовый `HtmlDomParser` (`RecordParserInterface`) с обходом карточек по `card_selector`/`card_xpath`, извлечением полей по `selector`/`xpath` и fallback-цепочками экстракторов;
  - добавлены unit-тесты `SourceResolverTest`, `XmlStreamParserTest`, `HtmlDomParserTest`; подтверждена работоспособность вместе с `YmlStreamParserTest`.
- Выполнен Этап 4 (нормализация и upsert):
  - добавлена таблица стабильной идентификации `product_supplier_references` (`supplier + external_id -> product_id`) + модель `ProductSupplierReference`;
  - в `Product` добавлена связь `supplierReferences()` для работы с mapping-слоем;
  - реализован `ProductPayloadNormalizer`: чистки строк (`trim`, html entities, whitespace), нормализация валюты (`RUR -> RUB`), цены/остатка и списка изображений;
  - реализован `ProductImportProcessor` (`ImportProcessorInterface`) с batch-обработкой (`processBatch`), upsert-логикой по стабильному ключу, опциями `create_missing`/`update_existing` и обновлением `last_seen_run_id`;
  - зафиксирована стратегия категорий в процессоре: новые товары автоматически привязываются к `Staged`, существующие обновляются без смены категорий;
  - реализован finalize для “missing” (`finalizeMissing`): деактивация выполняется только в `full_sync_authoritative`; в `partial_import` finalize пропускается;
  - добавлены unit-тесты `ProductImportProcessorTest` (normalization, stable key update, batch processing, missing-policy).

## Этап 0. Discovery (зафиксировано)

### Текущие 2 парсера/импорта товаров

Metalmaster (`app/Support/Metalmaster/*`)
- Тип источника: HTML-карточки товаров, URL берутся из “buckets” (сгенерированных из sitemap).
- Вход:
- `buckets_file` (по умолчанию `storage/app/parser/metalmaster-buckets.json`), генерируется командой `parser:sitemap-buckets`.
- Опции: `bucket` (фильтр категории), `limit`, `timeout`, `delay_ms`, `write|dry-run`, `publish`, `download_images`, `skip_existing`, `show_samples`.
- Обработка:
- `MetalmasterProductImportService::run()` загружает список URL из buckets JSON, затем последовательно делает HTTP GET по каждому URL и парсит HTML через `MetalmasterProductParser` (DOMDocument + XPath + JSON-LD Product).
- В `write`-режиме выполняет upsert в `products` по ключу `slug` (из URL). При `skip_existing=true` существующий `slug` пропускается.
- Все созданные/обновленные товары привязываются к категории `Staged` через `syncWithoutDetaching()` (категория при необходимости создается по `config('catalog-export.staging_category_slug')`).
- При `download_images=true` скачивает изображения в `storage/app/public/pics`, подменяет URL в `gallery/image/thumb`, и дополнительно “локализует” `<img>` внутри `description` (подменяет `src` на `/storage/...`). Для каждого уникального файла ставит в очередь `GenerateImageDerivativesJob`.
- Пишет в `products` (основные поля): `name`, `title`, `sku`, `brand`, `country`, `price_amount`, `discount_price`, `currency`, `in_stock`, `qty`, `is_active`, `short`, `description`, `extra_description`, `specs` (JSON), `promo_info`, `image`, `thumb`, `gallery` (JSON), `meta_title`, `meta_description`.
- Пишет в `products` только при создании: `slug` (из URL), `is_in_yml_feed=true`, `with_dns=true`.
- Выход: массив статистики (`found_urls/processed/errors/created/updated/skipped/...`), + `url_errors`, + `samples` (в dry-run).
- Ограничения/особенности:
- Для старта нужен актуальный buckets-файл (если файл отсутствует/битый, run падает с `fatal_error`).
- Парсинг не потоковый: на каждый товар загружается HTML страницы целиком, строится DOM.
- `is_active` выставляется в значение опции `publish` на каждом write-upsert (может деактивировать ранее активные товары при `publish=false`).
- Политики “missing” (исчезнувшие товары) нет: товары, пропавшие с сайта, сами по себе не деактивируются.

Vactool (`app/Support/Vactool/*`)
- Тип источника: HTML-карточки товаров, URL берутся из sitemap (рекурсивно, с поддержкой `.gz`).
- Вход:
- `sitemap` (по умолчанию `https://vactool.ru/sitemap.xml`) + `match` (фрагмент URL для карточек, по умолчанию `/catalog/product-`).
- Опции: `limit`, `delay_ms`, `write|dry-run`, `publish`, `download_images`, `skip_existing`, `show_samples`.
- Обработка:
- `VactoolSitemapCrawler` скачивает sitemap, при необходимости распаковывает `.gz`, и собирает URL рекурсивно (sitemapindex → urlset).
- `VactoolProductImportService::run()` фильтрует URL по `match`, затем последовательно делает HTTP GET и парсит HTML через `VactoolProductParser` (Symfony DomCrawler + JSON-LD + Inertia `#app[data-page]` + HTML fallback).
- В `write`-режиме выполняет upsert по эвристическому ключу `name + brand` (см. `findExistingProduct()`). При `skip_existing=true` найденный товар пропускается.
- Созданные/обновленные товары привязываются к `Staged` через `syncWithoutDetaching()`.
- При `download_images=true` скачивает изображения в `storage/app/public/pics` и ставит `GenerateImageDerivativesJob` (в отличие от Metalmaster, не переписывает `<img>` внутри `description`).
- Пишет в `products` (основные поля): `name`, `title`, `brand`, `price_amount`, `currency`, `in_stock`, `qty`, `is_active`, `description`, `specs` (JSON), `image`, `thumb`, `gallery` (JSON), `meta_title`, `meta_description`.
- `slug` не задается явно и генерируется в `Product::saving()` автоматически.
- Выход: массив статистики (`found_urls/processed/errors/created/updated/skipped/...`), + `url_errors`, + `samples` (в dry-run).
- Ограничения/особенности:
- Ключ `name + brand` не является стабильным внешним ID: переименование товара в источнике приведет к созданию нового товара в БД (дубль).
- `is_active` выставляется в значение опции `publish` на каждом write-upsert.
- Политики “missing” нет.

### Текущие точки входа импорта
- UI (Filament):
- `app/Filament/Pages/ProductImportExport.php` (Excel import/export; dry-run + apply; использует `ImportRun` и `ProductImportService`).
- `app/Filament/Pages/VactoolProductImport.php` (Vactool, запуск в очередь, создание `ImportRun`, остановка run через статус `cancelled`).
- `app/Filament/Pages/MetalmasterProductImport.php` (Metalmaster, запуск в очередь, buckets regeneration, остановка run через статус `cancelled`).
- `app/Filament/Resources/ImportRuns/*` (история импортов `ImportRunResource`).
- CLI:
- `php artisan parser:sitemap-buckets` (Metalmaster: генерация buckets JSON из sitemap).
- `php artisan parser:parse-products` (Metalmaster: импорт страниц; по умолчанию пишет в БД, если не указан legacy-флаг `--dry-run=1`).
- `php artisan products:parse-vactool` (Vactool: импорт страниц; пишет в БД только при `--write`).
- Jobs/Queue:
- `app/Jobs/RunMetalmasterProductImportJob.php`, `app/Jobs/RunVactoolProductImportJob.php` (обновляют `ImportRun->totals`, пишут `ImportIssue` на ошибки; поддерживают остановку через `status=cancelled`).
- `app/Jobs/GenerateImageDerivativesJob.php` (деривативы изображений, вызывается из обоих импортов при `download_images`).

### Обязательные поля внутренней модели Product (как сейчас в БД/домене)
- Минимум для записи в БД: `name` (NOT NULL).
- Технически обязательные NOT NULL поля: `name`, `slug`, `name_normalized` (но `slug` и `name_normalized` генерируются в `Product::saving()` автоматически, если не заданы).
- Дополнительно NOT NULL с дефолтами (не требуют данных из источника “для вставки”, но влияют на поведение): `price_amount` (0), `currency` ('RUB'), `in_stock` (1), `is_active` (1), `is_in_yml_feed` (1), `with_dns` (1), `popularity` (0).

### Риски/техдолг, выявленные в текущей реализации
- Изменение HTML/JSON-LD/Inertia структуры на стороне поставщика ломает парсинг (логика hardcoded, не конфигурируется профилем).
- Дубликаты и неверные обновления:
- Metalmaster ключится по `slug` (стабилен, но зависит от URL и buckets).
- Vactool ключится по `name + brand` (нестабилен; переименование создает дубль).
- `is_active` перезаписывается опцией `publish` на каждом upsert (при `publish=false` можно массово “снять с витрины” уже активные товары).
- Нет механизма “missing finalize”: исчезнувшие из источника товары остаются как были (активность/остатки не пересчитываются).
- Медиа грузится синхронно внутри импорта (дольше run, больше точек отказа); нет явных лимитов на размер/типы файлов; картинка читается целиком в память; возможен рост диска `storage/app/public/pics`.
- Разные дефолты CLI vs UI:
- Metalmaster CLI по умолчанию в write-режиме; Vactool CLI по умолчанию в dry-run.
- `Staged`-категория проставляется `syncWithoutDetaching()` и для обновлений тоже: “staged” может разрастаться и включать уже разнесенные по категориям товары.

## Принятые решения
- Поддерживаем общий import core и отдельные supplier adapters/profiles.
- Поддерживаем разные форматы через слой парсеров (`XmlStreamParser`, `HtmlDomParser` и т.д.).
- В качестве базы логирования/истории переиспользуем существующие `import_runs` + `import_issues` (без создания новых таблиц для run/errors).
- MetalMaster рассматривается как HTML-источник внутри общей архитектуры, а не как исключение.
- `Source` определяется выбранным `SupplierAdapter/Profile` (поставщик/профиль). Конкретный файл/URL относится к конкретному run и не обязан быть “полным снимком”.
- Вводим режимы прогона: `partial_import` (по умолчанию, без деактивации “отсутствующих”) и `full_sync_authoritative` (полный снимок, после прогона выполняем finalize “missing”).
- “Исчезнувший из фида/источника” определяется только для `full_sync_authoritative` в рамках одного `Source`: товар привязан к `Source`, но не встретился в run.
- Базовая стратегия finalize “missing” в `full_sync_authoritative`: `is_active=false`, `in_stock=false`, `qty=0`.
- Категории на сайте назначаются вручную: новые товары создаются в категории `Staged`, существующие обновляются без смены категорий (по умолчанию).
- Минимальный обязательный набор полей `ProductPayload`: `external_id`, `name`. `price/stock` и прочие поля валидируются в зависимости от режима/опций прогона.
- Ошибки делим на `fatal` (останавливают run) и `record-level` (логируем и продолжаем). Порог остановки по `record-level` настраиваемый per-run.
- Каждый прогон настраиваем по действиям: `check_presence/finalize_missing`, `create_missing`, `update_existing`, `download_media` (набор флагов/режимов).
- YML (Yandex Market Language) ложится как отдельный форматный парсер + отдельный адаптер:
- `YmlStreamParser` (форматный слой) читает фид потоково на `XMLReader` и yield-ит `offer`-записи по одной; в памяти держим только текущий offer и (опционально) карту `categoryId -> name` из `<categories>`.
- `SupplierAdapter/Profile` под названием `Yandex Market Feed` придерживается канона стандарта; отклонения допускаются только если они описаны самим стандартом.
- В `Yandex Market Feed` поддерживаем минимум 2 типа offer:
- Упрощенный: `name` берется из `<name>`.
- `vendor.model`: `name` строится как склейка `<typePrefix>? + <vendor> + <model>`.
- По дефолту `Yandex Market Feed` работает строго: если обязательные поля типа offer отсутствуют, это `record-level` ошибка и запись пропускается.
- Внешние библиотеки для XML/YML не протекают в import core; если и используются, то внутри конкретного форматного парсера и только при гарантии потоковой обработки.

## Открытые вопросы
- Нужен ли отдельный режим/флаг: “`restage_existing_on_change`” (перекладывать измененные товары обратно в `Staged`).
- Разрешаем ли создание `Staged` товара без `price` (и как его отображать/ограничивать в витрине).
- Дефолтные значения порогов ошибок (count/%), чтобы “из коробки” было безопасно.
- Какие типы offer YML поддерживаем сверх “упрощенный” и `vendor.model` и какая стратегия до поддержки: пропуск с ошибкой или частичный маппинг.

## Ближайшие шаги
1. Перейти к Этапу 5: медиа-пайплайн (вынести загрузку медиа из основного потока импорта в очередь).
2. Начать перенос Vactool/Metalmaster в parser+adapter-профили поверх общего pipeline (перенос raw-парсинга в `RecordParserInterface` + `SupplierAdapterInterface`).
3. До завершения миграции сохранить legacy shape прогресса/результата (`processed/errors/created/updated/skipped/fatal_error/url_errors/samples/no_urls`) для совместимости UI и текущих entrypoint.
4. После достижения parity и прохождения тестов переключить новый core-поток на run-статусы `running`/`completed`.
