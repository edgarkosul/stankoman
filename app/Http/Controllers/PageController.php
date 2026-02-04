<?php

namespace App\Http\Controllers;

use App\Models\Page;

class PageController extends Controller
{
    public function __invoke(Page $page)
    {
        // На первом этапе: показываем только опубликованные
        abort_unless($page->is_published, 404);

        return view('pages.show', [
            'page' => $page,
        ]);
    }
}
