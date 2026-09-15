<?php

namespace App\Support\Search;

/**
 * Итог поиска товаров по словам — что нашлось и как.
 *
 * @template TResult
 */
final readonly class SearchOutcome
{
    /**
     * @param  TResult  $result  то, что вернуло замыкание вызывающего: страница, коллекция, ключи
     * @param  string  $text  запрос в том виде, в каком он в итоге ушёл в индекс
     * @param  list<string>  $unmatched  слова запроса (как их набрал покупатель), по которым
     *                                   индекс не нашёл ни одного товара
     * @param  bool  $relaxed  результат получен повтором без слов из `$unmatched`
     */
    public function __construct(
        public mixed $result,
        public string $text,
        public array $unmatched = [],
        public bool $relaxed = false,
    ) {}
}
