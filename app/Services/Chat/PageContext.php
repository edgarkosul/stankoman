<?php

namespace App\Services\Chat;

/**
 * Контекст страницы в том виде, в каком его читает модель.
 *
 * Структуру собирает магазин (PageContextSource::describe()), и лежит она
 * в `chat_messages.page_context` — рядом с вопросом, заданным на этой
 * странице. Здесь только перевод в строки системного промпта.
 *
 * Машинные ключи модели не нужны: `in_stock: 1` она прочтёт хуже, чем
 * «наличие: в наличии», а `id` ей не нужен вовсе — он остаётся в базе
 * для экрана «Пробелы».
 */
final class PageContext
{
    /**
     * @param  array<string, mixed>|null  $context
     * @return array<string, string>|null
     */
    public static function toPrompt(?array $context): ?array
    {
        return match ($context['type'] ?? null) {
            'home' => ['страница' => 'главная страница магазина'],
            'catalog' => ['страница' => 'каталог магазина'],
            'product' => self::filled([
                'страница' => 'карточка товара',
                'товар' => $context['name'] ?? '',
                'артикул' => $context['sku'] ?? '',
                'наличие' => $context['availability'] ?? '',
                'цена' => $context['price_label'] ?? '',
                'ссылка' => $context['url'] ?? '',
            ]),
            'category' => self::filled([
                'страница' => 'раздел каталога',
                'раздел' => $context['breadcrumb'] ?? $context['name'] ?? '',
                'ссылка' => $context['url'] ?? '',
            ]),
            'page' => self::filled([
                'страница' => $context['title'] ?? 'информационная страница',
                'ссылка' => $context['url'] ?? '',
            ]),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, string>
     */
    private static function filled(array $fields): array
    {
        return array_filter(
            array_map(static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', $fields),
            static fn (string $value): bool => $value !== '',
        );
    }
}
