<?php

namespace App\Shop;

use App\Models\Page;
use App\Services\Kb\Contracts\KbSource;
use App\Services\Kb\Data\KbDocument;
use App\Services\Kb\HtmlKbTextExtractor;

/**
 * Информационные страницы сайта как источник базы знаний.
 *
 * Берём не все подряд, а по белому списку `ai_support.knowledge_base.static_pages`.
 * База знаний должна отвечать на вопросы, а не пересказывать витрину: страница,
 * которая выигрывает выдачу по словам «станок» и «поставка», ничего при этом
 * не отвечая, для бота хуже её отсутствия — почему в списке именно эти страницы,
 * записано в конфиге.
 *
 * Только опубликованные: иначе бот процитирует страницу и даст на неё ссылку,
 * которая отвечает 404.
 */
final class PageKbSource implements KbSource
{
    public const NAME = 'intertooler-page';

    /**
     * @param  list<string>  $slugs
     */
    public function __construct(
        private readonly HtmlKbTextExtractor $extractor,
        private readonly array $slugs,
        private readonly string $shopName,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    /** Входит ли страница с этим адресом в базу знаний. */
    public function covers(string $slug): bool
    {
        return in_array($slug, $this->slugs, true);
    }

    /**
     * @return iterable<KbDocument>
     */
    public function documents(): iterable
    {
        if ($this->slugs === []) {
            return;
        }

        $pages = Page::query()
            ->whereIn('slug', $this->slugs)
            ->where('is_published', true)
            ->orderBy('slug')
            ->get();

        foreach ($pages as $page) {
            $text = $this->extractor->toText($page->content);

            // Шесть опубликованных страниц сайта пустые (`<p></p>`): фрагмент
            // из одних крошек найдётся на что угодно и не ответит ни на что.
            if (trim($text) === '') {
                continue;
            }

            $title = (string) ($page->title ?: $page->slug);

            yield new KbDocument(
                key: (string) $page->slug,
                title: $title,
                // Крошки уходят в эмбеддинг префиксом каждого фрагмента, поэтому
                // здесь не служебный путь, а то, как человек назвал бы раздел.
                breadcrumb: array_values(array_filter([$this->shopName, $title])),
                text: $text,
                url: route('page.show', ['page' => $page->slug]),
            );
        }
    }
}
