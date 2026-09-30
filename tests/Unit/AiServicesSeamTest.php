<?php

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

// Приложение поднимается ради app_path(): файл сканирует дерево проекта.
uses(TestCase::class);

/*
 * Шов между ассистентом и магазином, проверяемый грепом.
 *
 * Правило: app/Services/{Ai,Kb,Catalog} не импортируют модели магазина.
 * Знание о том, что такое товар, категория, страница и настройка, живёт
 * в App\Shop и в провайдере, а сервисы получают его контрактами.
 *
 * Тест здесь потому, что у донора это правило было записано в плане и
 * выполнилось лишь там, где второй реализации требовала сиюминутная нужда:
 * `CatalogBrands` и `CatalogSections` читали Product напрямую. Разошлось
 * бы и у нас — не со злого умысла, а потому что `use App\Models\Product`
 * дописывается в одну строку и ничего не ломает в тот же день.
 *
 * Исключения — модели самого ассистента: статьи базы знаний магазину
 * не принадлежат, у них нет витрины и другой реализации.
 */

/*
 * Разговор, сообщения и расходная книга чата — тоже модели ассистента:
 * во втором магазине они те же самые. А заявка, товар, страница и
 * пользователь — магазинные, и чат получает их через PageContextSource
 * и LeadIntake.
 *
 * @var list<string>
 */
$allowed = [
    'App\Models\KbArticle',
    'App\Models\KbCategory',
    'App\Models\ChatConversation',
    'App\Models\ChatMessage',
    'App\Models\AiUsageEntry',
];

it('сервисы ассистента не знают моделей магазина', function () use ($allowed): void {
    $offenders = [];

    foreach (Finder::create()->files()->name('*.php')->in([
        app_path('Services/Ai'),
        app_path('Services/Kb'),
        app_path('Services/Chat'),
        app_path('Services/Catalog'),
    ]) as $file) {
        preg_match_all('/^use (App\\\\Models\\\\[A-Za-z]+)/m', (string) file_get_contents($file->getRealPath()), $matches);

        foreach ($matches[1] as $model) {
            if (! in_array($model, $allowed, true)) {
                $offenders[] = $file->getRelativePathname().' → '.$model;
            }
        }
    }

    expect($offenders)->toBe([], 'Модель магазина импортируется в сервисах ассистента: '
        .implode('; ', $offenders).'. Работать с ней должен App\Shop за контрактом.');
});

it('реализация шва живёт в App\Shop и моделями пользуется', function (): void {
    // Обратная сторона того же правила: если бы App\Shop ни одной модели
    // не импортировал, знание о магазине уехало бы обратно в сервисы.
    $lookup = (string) file_get_contents(app_path('Shop/EloquentProductLookup.php'));

    expect($lookup)->toContain('use App\Models\Product;')
        ->and($lookup)->toContain('implements ProductLookup');
});
