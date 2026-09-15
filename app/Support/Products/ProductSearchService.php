<?php

namespace App\Support\Products;

use App\Models\Product;
use App\Support\Search\LatinQuery;
use App\Support\Search\ProductTextSearch;
use App\Support\Search\SearchOutcome;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

class ProductSearchService
{
    /**
     * Запрос для поиска по словам. Правило общее с ассистентом — см. LatinQuery:
     * разойтись им нельзя, иначе бот и шапка сайта отвечают на один запрос по-разному.
     */
    public function normalizeQuery(string $query): string
    {
        return LatinQuery::normalize($query);
    }

    public function searchPage(string $query, int $perPage = 24): LengthAwarePaginator
    {
        return $this->searchPageOutcome($query, $perPage)->result;
    }

    /**
     * Страница поиска вместе с тем, как она найдена.
     *
     * Если выдача собрана без части слов, странице нужно сказать об этом
     * покупателю: иначе «электропитбайк white siberia belluga», показавший
     * электромотоциклы, выглядит ошибкой поиска.
     *
     * @return SearchOutcome<LengthAwarePaginator>
     */
    public function searchPageOutcome(string $query, int $perPage = 24): SearchOutcome
    {
        $normalizedQuery = $this->normalizeQuery($query);
        $outcome = $this->searchPageWithScout($query, $normalizedQuery, $perPage);

        if ($outcome !== null && $outcome->result->total() > 0) {
            return $outcome;
        }

        $unmatched = $outcome === null ? [] : $outcome->unmatched;

        return new SearchOutcome(
            $this->fallbackQuery($query, $normalizedQuery, $unmatched)
                ->with('categories')
                ->paginate($perPage),
            $normalizedQuery,
            $unmatched,
        );
    }

    /**
     * @return Collection<int, Product>
     */
    public function suggestions(string $query, int $limit = 8): Collection
    {
        $normalizedQuery = $this->normalizeQuery($query);
        $outcome = $this->searchSuggestionsWithScout($query, $normalizedQuery, $limit);

        if ($outcome !== null && $outcome->result->isNotEmpty()) {
            return $outcome->result;
        }

        return $this->fallbackQuery($query, $normalizedQuery, $outcome === null ? [] : $outcome->unmatched)
            ->limit($limit)
            ->get();
    }

    /**
     * @return SearchOutcome<LengthAwarePaginator>|null
     */
    private function searchPageWithScout(string $query, string $normalizedQuery, int $perPage): ?SearchOutcome
    {
        if ($normalizedQuery === '') {
            return null;
        }

        try {
            return app(ProductTextSearch::class)->run(
                $query,
                fn ($search): LengthAwarePaginator => $search
                    ->query(
                        fn (Builder $builder): Builder => $builder
                            ->with('categories')
                            ->where('is_active', true)
                    )
                    ->paginate($perPage),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return SearchOutcome<Collection<int, Product>>|null
     */
    private function searchSuggestionsWithScout(string $query, string $normalizedQuery, int $limit): ?SearchOutcome
    {
        if ($normalizedQuery === '') {
            return null;
        }

        try {
            return app(ProductTextSearch::class)->run(
                $query,
                fn ($search): Collection => $search
                    ->query(
                        fn (Builder $builder): Builder => $builder->where('is_active', true)
                    )
                    ->take($limit)
                    ->get(),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $unmatched  слова, по которым индекс не нашёл ни одного товара
     */
    private function fallbackQuery(string $query, string $normalizedQuery, array $unmatched = []): Builder
    {
        /*
         * Слова, которых нет в индексе, ищем ещё и ВНУТРИ слов названия.
         * Meilisearch середину слова не ищет: «трицикл двухместный» не находил
         * ни одного «Электротрицикла», хотя их пять. Короткие слова не берём —
         * «гбо» или «шм» внутри чужих слов найдутся где угодно.
         */
        $terms = collect([$query, $normalizedQuery])
            ->merge(array_filter($unmatched, static fn (string $word): bool => preg_match_all('/\p{L}/u', $word) >= 5))
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

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\%_');
    }
}
