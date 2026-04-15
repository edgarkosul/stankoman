<?php

namespace App\Livewire\Header;

use App\Support\Products\ProductSearchService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Search extends Component
{
    public string $class = '';

    public string $q = '';

    public bool $open = false;

    public int $active = -1; // индекс подсвеченного результата

    #[Computed]
    public function results(): Collection
    {
        if (mb_strlen(trim($this->q)) < 2) {
            return collect();
        }

        return app(ProductSearchService::class)->suggestions($this->q, 8);
    }

    public function updatedQ(): void
    {
        $this->open = mb_strlen(trim($this->q)) >= 2 && $this->results->isNotEmpty();
        $this->active = -1;
    }

    public function goFull(): mixed
    {
        if (! Route::has('search')) {
            return null;
        }

        return redirect()->route('search', ['q' => $this->q]);
    }

    public function goTo(string $productSlug): mixed
    {
        return redirect()->route('product.show', $productSlug);
    }

    public function highlight(string $text): string
    {
        $q = preg_quote(trim($this->q), '/');
        if ($q === '') {
            return e($text);
        }

        return preg_replace('/('.$q.')/iu', '<mark class="bg-yellow-200">$1</mark>', e($text));
    }

    public function render(): View
    {
        return view('livewire.header.search');
    }
}
