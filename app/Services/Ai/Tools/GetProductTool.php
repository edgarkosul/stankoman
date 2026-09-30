<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Contracts\ProductLookup;

/**
 * Карточка одного товара: наличие, цена, гарантия, характеристики, описание.
 *
 * Сама карточка и её цена собираются за швом (ProductLookup): инструмент
 * не знает, как магазин решает, кому показывать скидку, и поэтому не может
 * ошибиться в этом решении. Донорский `describe()` с ценовой политикой
 * kratonshop стал здесь ProductCard::toPromptText() — чистым форматированием.
 */
final class GetProductTool implements AssistantTool
{
    public function __construct(private readonly ProductLookup $products) {}

    public function name(): string
    {
        return 'get_product';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => 'Карточка товара по артикулу или адресу: наличие, цена, '
                    .'гарантия, ссылка, ХАРАКТЕРИСТИКИ и описание с сайта. Вызывай каждый '
                    .'раз, когда речь о конкретном товаре — о цене, наличии, гарантии, '
                    .'мощности, размерах, комплектации, о том, что он умеет: эти данные '
                    .'у каждой модели свои, меняются, и выводить их из названия нельзя.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'sku' => ['type' => 'string', 'description' => 'Артикул товара '
                            .'или обозначение модели с карточки.'],
                        'slug' => ['type' => 'string', 'description' => 'Адресная часть карточки товара.'],
                    ],
                ],
            ],
        ];
    }

    public function run(array $arguments, ToolContext $context): string
    {
        if ($context->alreadyCalled($this->name(), $arguments)) {
            return 'Такой вызов в этом ходе уже был, и повтор ничего нового не вернёт. '
                .'Ответь покупателю по тому, что уже нашлось, или измени запрос.';
        }

        $sku = trim((string) ($arguments['sku'] ?? ''));
        $slug = trim((string) ($arguments['slug'] ?? ''));

        if ($sku === '' && $slug === '') {
            return 'Нужен артикул или адрес карточки. Найди товар через search_products.';
        }

        $card = $this->products->find($sku, $slug, $context->seesDiscounts);

        if ($card === null) {
            return 'Товар не найден. Не придумывай его характеристики — уточни у покупателя '
                .'название или передай вопрос менеджеру.';
        }

        $context->noteCard($card);

        if (! $context->seesDiscounts) {
            $context->withholdPrices($this->products->memberPrices([$card->id]));
        }

        return $card->toPromptText();
    }
}
