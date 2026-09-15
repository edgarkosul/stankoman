<?php

namespace App\Services\Kb\Sources;

use App\Models\KbArticle;
use App\Services\Kb\Contracts\KbSource;
use App\Services\Kb\Data\KbDocument;
use App\Services\Kb\TiptapTextExtractor;

/**
 * Статьи, написанные админом, как источник базы знаний.
 *
 * Здесь это не дополнение, а основной источник: полезного текста на сайте
 * около пяти тысяч знаков, и базу знаний intertooler надо писать, а не собирать.
 *
 * Черновики не отдаём вовсе. Админ дописывает статью не за один заход,
 * и полуфраза, процитированная ботом покупателю, хуже её отсутствия.
 */
final class KbArticleKbSource implements KbSource
{
    public const NAME = 'intertooler-kb';

    public function __construct(
        private readonly TiptapTextExtractor $extractor,
        private readonly string $shopName,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @return iterable<KbDocument>
     */
    public function documents(): iterable
    {
        /*
         * lazy(), а не get(): точечная переиндексация одной статьи идёт
         * перебором этого же генератора до совпадения ключа, и грузить ради
         * неё весь корпус в память незачем.
         */
        $articles = KbArticle::query()
            ->published()
            ->with('category')
            ->orderBy('id')
            ->lazy();

        foreach ($articles as $article) {
            $document = $this->toDocument($article);

            if ($document !== null) {
                yield $document;
            }
        }
    }

    /**
     * Статья в виде документа для индексации, либо null — если публиковать
     * нечего: снята с публикации, удалена или текст пуст.
     */
    public function toDocument(KbArticle $article): ?KbDocument
    {
        if (! $article->is_published || $article->trashed()) {
            return null;
        }

        $text = $this->extractor->toText($article->content);

        if (trim($text) === '') {
            return null;
        }

        return new KbDocument(
            key: $article->documentKey(),
            title: (string) $article->title,
            /*
             * Крошки уходят в эмбеддинг префиксом каждого фрагмента, поэтому
             * это не служебный путь, а то, как человек назвал бы место:
             * «InterTooler.ru → Гарантия и сервис → Куда везти станок в ремонт».
             * Раздел здесь единственная польза от kb_categories.
             */
            breadcrumb: array_values(array_filter([
                $this->shopName,
                $article->category?->name,
                (string) $article->title,
            ])),
            text: $text,
            // Своей страницы у статьи нет; ссылка появляется, только если
            // статья опирается на реальную страницу сайта.
            url: $article->public_url ?: null,
        );
    }
}
