<?php

namespace App\Support\Products;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class ProductSearchService
{
    public function normalizeQuery(string $query): string
    {
        $query = trim((string) preg_replace('/\s+/u', ' ', $query));

        if ($query === '') {
            return '';
        }

        if (preg_match('/\p{Cyrillic}/u', $query) === 1) {
            return $this->toLatin($query);
        }

        return $query;
    }

    public function searchPage(string $query, int $perPage = 24): LengthAwarePaginator
    {
        $normalizedQuery = $this->normalizeQuery($query);
        $scoutResults = $this->searchPageWithScout($normalizedQuery, $perPage);

        if ($scoutResults instanceof LengthAwarePaginator && $scoutResults->total() > 0) {
            return $scoutResults;
        }

        return $this->fallbackQuery($query, $normalizedQuery)
            ->with('categories')
            ->paginate($perPage);
    }

    /**
     * @return Collection<int, Product>
     */
    public function suggestions(string $query, int $limit = 8): Collection
    {
        $normalizedQuery = $this->normalizeQuery($query);
        $scoutResults = $this->searchSuggestionsWithScout($normalizedQuery, $limit);

        if ($scoutResults->isNotEmpty()) {
            return $scoutResults;
        }

        return $this->fallbackQuery($query, $normalizedQuery)
            ->limit($limit)
            ->get();
    }

    private function searchPageWithScout(string $query, int $perPage): ?LengthAwarePaginator
    {
        if ($query === '') {
            return null;
        }

        try {
            return Product::search($query)
                ->query(
                    fn (Builder $builder): Builder => $builder
                        ->with('categories')
                        ->where('is_active', true)
                )
                ->paginate($perPage);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return Collection<int, Product>
     */
    private function searchSuggestionsWithScout(string $query, int $limit): Collection
    {
        if ($query === '') {
            return collect();
        }

        try {
            return Product::search($query)
                ->query(
                    fn (Builder $builder): Builder => $builder->where('is_active', true)
                )
                ->take($limit)
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    private function fallbackQuery(string $query, string $normalizedQuery): Builder
    {
        $terms = collect([$query, $normalizedQuery])
            ->map(fn (string $term): string => trim((string) preg_replace('/\s+/u', ' ', $term)))
            ->filter()
            ->unique()
            ->values();

        return Product::query()
            ->where('is_active', true)
            ->where(function (Builder $builder) use ($terms): void {
                foreach ($terms as $term) {
                    $likeTerm = '%'.$this->escapeLike($term).'%';

                    $builder
                        ->orWhere('name', 'like', $likeTerm)
                        ->orWhere('slug', 'like', $likeTerm)
                        ->orWhere('sku', 'like', $likeTerm)
                        ->orWhere('brand', 'like', $likeTerm)
                        ->orWhere('name_normalized', 'like', $likeTerm);
                }
            })
            ->orderByDesc('popularity')
            ->orderBy('name');
    }

    private function toLatin(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (function_exists('transliterator_transliterate')) {
            $latin = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        } else {
            $latin = Str::lower(Str::ascii($text));
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) $latin));
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\%_');
    }
}
