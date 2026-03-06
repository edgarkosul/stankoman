<?php

namespace App\Support\CatalogImport\Runs;

final class ImportRunEventLabels
{
    /**
     * @var array<string, string>
     */
    private const STAGE_LABELS = [
        'prefilter' => 'Предфильтр',
        'mapping' => 'Маппинг',
        'processing' => 'Обработка',
        'finalize' => 'Финализация',
        'runtime' => 'Выполнение',
    ];

    /**
     * @var array<string, string>
     */
    private const RESULT_LABELS = [
        'created' => 'Создан',
        'updated' => 'Обновлен',
        'unchanged' => 'Без изменений',
        'skipped' => 'Пропущен',
        'deactivated' => 'Деактивирован',
        'error' => 'Ошибка',
        'fatal' => 'Критическая ошибка',
    ];

    /**
     * @return array<string, string>
     */
    public static function stageOptions(): array
    {
        return self::STAGE_LABELS;
    }

    /**
     * @return array<string, string>
     */
    public static function resultOptions(): array
    {
        return self::RESULT_LABELS;
    }

    public static function stageLabel(?string $stage): string
    {
        if (! is_string($stage) || trim($stage) === '') {
            return '';
        }

        $normalized = trim($stage);

        return self::STAGE_LABELS[$normalized] ?? $normalized;
    }

    public static function resultLabel(?string $result): string
    {
        if (! is_string($result) || trim($result) === '') {
            return '';
        }

        $normalized = trim($result);

        return self::RESULT_LABELS[$normalized] ?? $normalized;
    }
}
