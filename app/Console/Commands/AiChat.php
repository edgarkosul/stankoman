<?php

namespace App\Console\Commands;

use App\Services\Ai\Data\AssistantReply;
use App\Services\Ai\ShopAssistant;
use Illuminate\Console\Command;

/**
 * Диалог с ассистентом из консоли — инструмент работы над промптом.
 *
 * Показывает не только ответ, но и то, как он получился: какие инструменты
 * вызывались, что нашлось в базе и с какой релевантностью, сколько стоило.
 * Без этого «почему он так ответил» решается чтением логов.
 *
 * Ручного тыканья тут НЕ достаточно, и это стоит помнить: недетерминированность
 * цикла проявляется на повторах, а не на одном удачном вопросе. Систематическую
 * проверку делает ai:bench (этап 1C).
 */
class AiChat extends Command
{
    protected $signature = 'ai:chat
        {question?* : Задать один вопрос и выйти}
        {--page= : Контекст страницы: product=Компрессор Hansmann RSE 7.5-8, category=…, page=… или полностью type=product,name=…,sku=…}
        {--quiet-tools : Не показывать разбор вызовов}';

    protected $description = 'Поговорить с ИИ-ассистентом магазина';

    public function handle(ShopAssistant $assistant): int
    {
        $sessionId = 'ai-chat-'.bin2hex(random_bytes(6));
        $page = $this->parsePage();
        $history = [];

        $single = implode(' ', (array) $this->argument('question'));

        if ($single !== '') {
            $this->turn($assistant, $single, $history, $sessionId, $page);

            return self::SUCCESS;
        }

        $this->line('<comment>Диалог с ассистентом.</comment> Пустая строка или «выход» — закончить.');
        $this->line('<fg=gray>Сессия '.$sessionId.' — внутри неё работает кеш промпта.</>');

        while (true) {
            $question = (string) $this->ask('вы');

            if (trim($question) === '' || in_array(mb_strtolower(trim($question)), ['выход', 'exit', 'q'], true)) {
                return self::SUCCESS;
            }

            $this->turn($assistant, $question, $history, $sessionId, $page);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $history
     * @param  array<string, mixed>|null  $page
     */
    private function turn(
        ShopAssistant $assistant,
        string $question,
        array &$history,
        string $sessionId,
        ?array $page,
    ): void {
        $reply = $assistant->ask($question, $history, sessionId: $sessionId, page: $page);

        // Историю ведём ту, что вернул агент: в ней уже лежат ходы с вызовами
        // инструментов и их результатами, без которых следующий ход придёт
        // с висящими tool_call_id.
        $history = $reply->messages;

        $this->newLine();

        if ($reply->isFailure()) {
            $this->line('<fg=red>бот не ответил</> — '.$this->explain($reply->stopReason));
        } else {
            $this->line('<info>бот</info>  '.str_replace("\n", "\n      ", $reply->text));
        }

        if (! $this->option('quiet-tools')) {
            $this->details($reply);
        }

        $this->newLine();
    }

    private function details(AssistantReply $reply): void
    {
        $this->newLine();

        if ($reply->toolCalls !== []) {
            // Имена, а не сами вызовы: с фазы 3.1 в toolCalls лежат
            // массивы с аргументами и временем, и implode на них падал.
            $this->line('<fg=gray>  вызовы: '.implode(' → ', $reply->toolNames()).'</>');
        } else {
            $this->line('<fg=gray>  вызовов инструментов не было</>');
        }

        foreach ($reply->citations as $citation) {
            $this->line(sprintf('<fg=gray>  %.4f  %s</>', $citation['score'], $citation['title']));
        }

        $flags = array_filter([
            $reply->escalated ? 'эскалация' : null,
            $reply->callbackRequested ? 'форма контактов' : null,
            $reply->isKbMiss((float) config('ai_support.knowledge_base.min_score')) ? 'kb_miss' : null,
        ]);

        $this->line(sprintf(
            // Модель — из ответа шлюза, а не из конфига: перепродавец
            // может подставить другую, и заметить это надо здесь.
            '<fg=gray>  %s · %s · %d/%d токенов (%d из кэша) · %.4f ₽ · %d мс%s</>',
            $reply->model ?: 'модель не названа',
            $reply->stopReason,
            $reply->inputTokens,
            $reply->outputTokens,
            $reply->cachedTokens,
            $reply->costRub,
            $reply->latencyMs,
            $flags !== [] ? ' · '.implode(', ', $flags) : '',
        ));
    }

    private function explain(string $reason): string
    {
        return match ($reason) {
            'max_iterations' => 'исчерпал шаги, так и не дав текста',
            'empty' => 'вернул пустоту',
            'contaminated' => 'проговорился об идентичности — ответ скрыт',
            'placeholder_leak' => 'выдал заглушку редактора вместо контакта — ответ скрыт',
            'pii_blocked' => 'шлюз заблокировал запрос из-за персональных данных',
            default => 'ошибка вызова',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parsePage(): ?array
    {
        $raw = (string) $this->option('page');

        if (trim($raw) === '') {
            return null;
        }

        $page = [];

        foreach (explode(',', $raw) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if (trim($key) !== '') {
                $page[trim($key)] = trim($value);
            }
        }

        /*
         * Сокращение из подписи команды — «product=Компрессор Hansmann RSE 7.5-8».
         * Ключ `type` обязателен: по нему промпт понимает, где стоит покупатель.
         * У донора без него половина живых проверок 14.09.2026 прошла так, будто
         * покупатель вообще не на карточке, и бот назвал цену, которой у товара
         * нет. Настоящий сборщик контекста страницы — это фаза 4 (шов
         * PageContextSource); здесь контекст задаётся рукой.
         */
        foreach (['product' => 'name', 'category' => 'name', 'page' => 'title'] as $type => $field) {
            if (! isset($page['type']) && isset($page[$type])) {
                $page = ['type' => $type, $field => $page[$type]] + $page;
                unset($page[$type]);
            }
        }

        return $page ?: null;
    }
}
