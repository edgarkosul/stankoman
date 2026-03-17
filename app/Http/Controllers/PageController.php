<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Support\Seo\SiteSeoDataBuilder;
use Illuminate\Contracts\View\View;

class PageController extends Controller
{
    public function __invoke(Page $page, SiteSeoDataBuilder $seoBuilder): View
    {
        abort_unless($page->is_published, 404);

        return view('pages.show', [
            'page' => $page,
            'seo' => [
                'description' => $page->meta_description ?: $seoBuilder->descriptionFromHtml($page->content),
            ],
        ]);
    }
}
