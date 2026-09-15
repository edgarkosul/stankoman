<?php

namespace App\Observers;

use App\Jobs\ReindexKbDocumentJob;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Shop\PageKbSource;
use App\Support\Menu\MenuService;

class PageObserver
{
    public function saving(Page $page): void
    {
        // Если slug меняется — надо забыть кеш уже сейчас (на случай, если где-то читают до saved)
        if ($page->exists && $page->isDirty('slug')) {
            $this->forgetMenusThatReference($page);
        }
    }

    public function created(Page $page): void
    {
        $this->reindexKnowledgeBase($page);
    }

    public function updated(Page $page): void
    {
        // Правка одного meta_title в текст для бота не попадает — воркер будить незачем.
        if ($page->wasChanged(['title', 'slug', 'content', 'is_published'])) {
            $this->reindexKnowledgeBase($page);
        }
    }

    public function saved(Page $page): void
    {
        $this->forgetMenusThatReference($page);
    }

    public function deleted(Page $page): void
    {
        $this->forgetMenusThatReference($page);
        $this->reindexKnowledgeBase($page);
    }

    /**
     * Страница из базы знаний ассистента — в очередь на переиндексацию.
     *
     * Без этого менеджер правит «Доставку и оплату», а бот до ночного прохода
     * отвечает по-старому. Белый список проверяется здесь, а не в джобе: правка
     * «Вакансий» не должна будить воркер, чтобы он выяснил, что делать нечего.
     *
     * Прежний slug тоже в счёт (в `updated` оригинал ещё не синхронизирован):
     * переименованную страницу источник под старым ключом больше не отдаст,
     * и джоба снесёт её фрагменты — иначе бот давал бы ссылку, которая отвечает
     * 404. Снятие с публикации и удаление разбираются так же: документа
     * в источнике нет — фрагменты уходят.
     */
    private function reindexKnowledgeBase(Page $page): void
    {
        $source = app(PageKbSource::class);
        $slugs = array_unique([(string) $page->slug, (string) $page->getOriginal('slug')]);

        foreach ($slugs as $slug) {
            if ($slug !== '' && $source->covers($slug)) {
                ReindexKbDocumentJob::dispatch(PageKbSource::NAME, $slug);
            }
        }
    }

    private function forgetMenusThatReference(Page $page): void
    {
        $menuIds = MenuItem::query()
            ->where('type', 'page')
            ->where('page_id', $page->id)
            ->distinct()
            ->pluck('menu_id')
            ->all();

        if (! count($menuIds)) {
            return;
        }

        $keys = Menu::query()->whereIn('id', $menuIds)->pluck('key')->all();

        $service = app(MenuService::class);
        foreach ($keys as $key) {
            $service->forget($key);
        }
    }
}
