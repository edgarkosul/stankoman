<?php

declare(strict_types=1);

/*
 * Сторож поискового индекса.
 *
 * Товар попадает в Meilisearch через события модели, а массовая запись
 * запросом (`DB::table('products')->update(...)`, `Product::query()->…->delete()`)
 * события не поднимает: документ остаётся со старым названием и ценой, а
 * массовая активация выкатывает на сайт товар, которого нет в поиске.
 *
 * Правило простое: файл живого кода, который пишет в товары массово, обязан
 * рядом позвать ProductSearchSync. Либо — если звать действительно нечего —
 * попасть в список исключений здесь, с причиной.
 *
 * Консольные команды не проверяются намеренно: это разовые миграции данных,
 * их подбирает ночная сверка `search:audit --fix`. Требовать от каждой такой
 * команды знания про индекс — цена выше пользы.
 */

$projectRoot = dirname(__DIR__, 2);

/** Где действует правило. */
$scannedRoots = [
    'app/Filament',
    'app/Http',
    'app/Jobs',
    'app/Livewire',
    'app/Models',
    'app/Observers',
    'app/Services',
    'app/Support',
];

/** Файл => почему запись здесь не требует синхронизации. */
$allowed = [
    'app/Models/Product.php' => 'setPrimaryCategory двигает только is_primary в пивоте, состав категорий не меняется; replacePrimaryCategory зовут из массового редактора, а он синхронизацию делает сам',
    'app/Support/CartService.php' => 'товар только читает (findOrFail), пишет в позиции корзины',
    'app/Support/Products/ProductCurrencyRateSyncService.php' => 'пересчитывает цены через $product->update() — события модели стреляют, Scout переиндексирует сам',
    'app/Support/Products/CategoryFilterImportService.php' => 'пишет значения атрибутов и опции, товары только читает; в поисковом документе этих полей нет',
    'app/Filament/Resources/Attributes/RelationManagers/ProductsUnifiedRelationManager.php' => 'пишет значения атрибутов (PAV и опции), которых в поисковом документе нет; сами товары читаются на exists()',
];

test('массовая запись в товары не проходит мимо поискового индекса', function () use ($projectRoot, $scannedRoots, $allowed) {
    $targets = '/DB::table\(\s*[\'"](products|product_categories)[\'"]\s*\)|Product::(query|whereKey|where[A-Z][A-Za-z]*)\(/';
    $writes = '/->(update|updateOrInsert|insert|insertOrIgnore|upsert|delete|forceDelete|increment|decrement|truncate)\(/';

    /*
     * Отдельно — привязка категорий через пивот. Состав категорий лежит
     * в документе (category_ids, по нему фильтруют витрина и поиск), а пивот
     * событий модели не поднимает вовсе: ни attach, ни detach, ни sync.
     * Донор этого случая в стороже не проверял и починил только те места,
     * которые нашёл глазами.
     */
    $pivot = '/->categories\(\)\s*->\s*(attach|detach|sync|syncWithoutDetaching|toggle|updateExistingPivot)\(/';

    $offenders = [];

    foreach ($scannedRoots as $root) {
        $directory = $projectRoot.'/'.$root;

        if (! is_dir($directory)) {
            continue;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace($projectRoot.'/', '', $file->getPathname());

            if (array_key_exists($relative, $allowed)) {
                continue;
            }

            // Комментарии выбрасываем: в них про запись мимо событий как раз
            // и написано, и на объяснение сторож реагировать не должен.
            $code = '';

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                        continue;
                    }

                    $code .= $token[1];

                    continue;
                }

                $code .= $token;
            }

            $bulkWrite = preg_match($targets, $code) && preg_match($writes, $code);

            if (! $bulkWrite && ! preg_match($pivot, $code)) {
                continue;
            }

            if (str_contains($code, 'ProductSearchSync')) {
                continue;
            }

            $offenders[] = $relative;
        }
    }

    sort($offenders);

    expect($offenders)->toBe([], implode("\n", [
        'Эти файлы пишут в товары (или в их категории) мимо событий модели и не зовут ProductSearchSync:',
        ...array_map(static fn (string $file): string => '  - '.$file, $offenders),
        'Добавь синхронизацию (ProductSearchSync::syncIds / queueIds / removeIds) или впиши файл в список исключений с причиной.',
    ]));
});
