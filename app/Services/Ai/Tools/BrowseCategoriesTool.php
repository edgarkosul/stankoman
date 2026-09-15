<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Contracts\ProductLookup;

/**
 * Разделы каталога по слову покупателя.
 *
 * Существует из-за арифметики, замеренной у донора 04.09.2026: на «нужен
 * пылесос» поиск отдаёт больше сотни товаров, и показать из них пять — это
 * лотерея, а не консультация. Но сто пылесосов — это сумма нескольких разных
 * ответов на разные вопросы, и развилку между ними магазин уже провёл сам,
 * когда разложил каталог по разделам. Боту остаётся её показать и спросить.
 *
 * Отдаются только ЛИСТОВЫЕ разделы: только у них есть своя страница
 * в каталоге, и только их можно дать ссылкой. Как они ищутся — в App\Shop\CatalogSections.
 */
final class BrowseCategoriesTool implements AssistantTool
{
    /** Сколько разделов показывать. Больше — это уже не развилка, а список. */
    private const LIMIT = 6;

    public function __construct(private readonly ProductLookup $products) {}

    public function name(): string
    {
        return 'browse_categories';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => 'Разделы каталога по слову покупателя, с числом товаров '
                    .'и ссылкой на каждый. Вызывай, когда покупатель назвал ВИД техники, '
                    .'но не модель: «нужен пылесос», «ищу компрессор», «что есть из '
                    .'сварочного». Разделы придумал магазин, и они — готовая развилка: '
                    .'покажи их, спроси, какой ближе, и только потом ищи товары '
                    .'через search_products с category_id.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Вид техники словом покупателя: «пылесос», «компрессор».',
                        ],
                    ],
                    'required' => ['query'],
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

        $query = trim((string) ($arguments['query'] ?? ''));

        if ($query === '') {
            return 'Пустой запрос. Уточни у покупателя, какая техника его интересует.';
        }

        $sections = $this->products->sections($query, self::LIMIT);

        if ($sections === []) {
            return 'Разделов по этому запросу нет. Не выдумывай их: поищи товары через '
                .'search_products или передай вопрос менеджеру.';
        }

        $blocks = [];

        foreach ($sections as $section) {
            $blocks[] = 'Раздел: '.$section->path."\n"
                .'Товаров: '.$section->productsCount."\n"
                .'Ссылка: '.$section->url."\n"
                .'category_id: '.$section->id;
        }

        return 'Разделы каталога по запросу «'.$query."»:\n\n".implode("\n\n", $blocks)
            ."\n\nЭто разделы магазина, а не подбор. Покажи их покупателю ссылками, "
            .'спроси, какой ближе к его задаче, и сузь поиск через search_products '
            .'с category_id. Не выбирай за него.';
    }
}
