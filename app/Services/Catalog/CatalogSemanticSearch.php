<?php

namespace App\Services\Catalog;

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Support\PiiRedactor;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Смысловой поиск по каталогу: вектор запроса плюс зеркало.
 *
 * Отдельный класс, а не метод в поиске товаров, потому что у него ровно одна
 * обязанность — ответить «искать по смыслу нечем» так, чтобы вызывающему
 * осталось просто искать словами. Нечем бывает в трёх случаях, и все три
 * рабочие, а не аварийные: зеркало ещё не собрано (до первого
 * `ai:catalog-embed`), шлюз не ответил на вектор запроса, зеркало сломано.
 * Во всех трёх поиск продолжает работать по названию, артикулу и бренду —
 * хуже подбирая, но не отказывая покупателю.
 *
 * Вектор считается по ОРИГИНАЛЬНОЙ формулировке покупателя, а слова ищутся
 * в нормализованной (латиница, написание бренда вместо прочтения), и это
 * не мелочь: смысл живёт в словах, а не в их записи. «Компрессор для гаража»
 * эмбеддер понимает, «kompressor dlya garazha» — нет.
 */
final class CatalogSemanticSearch
{
    /**
     * Зеркало готово — спрашивали и убедились.
     *
     * Кэшируется только «да»: после первого прогона команды зеркало уже
     * не опустеет, а вот залипшее «нет» пережило бы в воркере очереди
     * тот самый первый прогон и оставило бы бота без смысла до перезапуска.
     */
    private bool $ready = false;

    /**
     * @param  Closure(): LlmClient  $llm  клиент шлюза создаётся ЛЕНИВО и только
     *                                     когда вектор действительно нужен: без
     *                                     ключа его конструктор бросает, а поиск
     *                                     товаров обязан работать и без шлюза —
     *                                     словами. Иначе пустой AI_GATEWAY_KEY
     *                                     ронял бы каталог в местах, где про
     *                                     ассистента никто не спрашивал
     */
    public function __construct(
        private readonly CatalogSemanticIndex $index,
        private readonly Closure $llm,
        private readonly PiiRedactor $redactor,
    ) {}

    /**
     * Вектор запроса покупателя — или пустой массив, если искать по смыслу нечем.
     *
     * Считается тем же способом, что и вектор вопроса к базе знаний, то есть
     * с кэшем по формулировке (AitunnelLlmClient): у магазинного поиска
     * короткий хвост, и «нужен компрессор» спрашивают изо дня в день.
     *
     * @return list<float>
     */
    public function vectorFor(string $query): array
    {
        $query = trim($query);

        if ($query === '' || ! $this->ready()) {
            return [];
        }

        try {
            return ($this->llm)()->embed([$this->redactor->redact($query)], 'query')->vectors[0] ?? [];
        } catch (Throwable $e) {
            // Промах эмбеддинга не должен оставлять покупателя без выдачи:
            // выше по стеку это значит «ищем словами».
            Log::warning('Вектор запроса к каталогу не посчитался', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Гибридный поиск по зеркалу.
     *
     * @param  string  $keywords  слова в том виде, в каком они ушли бы в витринный индекс
     * @param  list<float>  $vector  вектор оригинальной формулировки
     * @param  bool  $designation  покупатель назвал обозначение: артикул, модель или бренд
     * @return list<int>|null id товаров; null — зеркало не ответило, ищите словами
     */
    public function keys(string $keywords, array $vector, string $filter, int $limit, bool $designation): ?array
    {
        if ($vector === []) {
            return null;
        }

        try {
            return $this->index->search($keywords, $vector, $filter, $limit, $designation);
        } catch (Throwable $e) {
            Log::warning('Зеркало каталога не ответило на поиск', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function ready(): bool
    {
        return $this->ready = $this->ready || $this->index->ready();
    }
}
