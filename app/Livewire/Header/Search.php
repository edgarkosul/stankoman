<?php

namespace App\Livewire\Header;

use App\Models\Product;
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
        $q = trim($this->q);
        if (mb_strlen($q) < 2) {
            return collect();
        }
        $q = $this->normalizedQuery($q);

        return Product::search($q)
            ->take(8)
            ->get(['id', 'slug', 'name', 'sku', 'price', 'discount_price']);
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

    protected function toLatin(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (function_exists('transliterator_transliterate')) {
            $latin = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        } else {
            $latin = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii($text));
        }

        return trim(preg_replace('/\s+/u', ' ', $latin));
    }

    protected function normalizedQuery(string $q): string
    {
        $q = trim(preg_replace('/\s+/u', ' ', $q));
        if ($q === '') {
            return $q;
        }

        // Если есть кириллица — ищем по латинице
        if (preg_match('/\p{Cyrillic}/u', $q)) {
            return $this->toLatin($q);
        }

        return $q; // иначе как есть
    }

    public function render(): View
    {
        return view('livewire.header.search');
    }
}
