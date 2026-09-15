<?php

namespace App\Observers;

use App\Jobs\ReindexKbDocumentJob;
use App\Models\KbArticle;
use App\Services\Kb\Sources\KbArticleKbSource;

/**
 * Правка статьи — переиндексация в очередь.
 *
 * Без обсервера админ правит текст, а бот до ночного прохода отвечает
 * по-старому. Замкнутый контур «исправил — проверил в песочнице» без этого
 * не работает вовсе: админ не увидит результата своей правки.
 */
class KbArticleObserver
{
    public function saved(KbArticle $article): void
    {
        $this->queue($article);
    }

    public function deleted(KbArticle $article): void
    {
        // Мягкое удаление тоже сюда: джоба сама увидит, что документа больше
        // нет, и снесёт его фрагменты.
        $this->queue($article);
    }

    public function restored(KbArticle $article): void
    {
        $this->queue($article);
    }

    private function queue(KbArticle $article): void
    {
        ReindexKbDocumentJob::dispatch(KbArticleKbSource::NAME, $article->documentKey());
    }
}
