<?php

namespace App\Console\Commands;

use App\Providers\AiSupportServiceProvider;
use App\Services\Ai\AssistantConfig;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Catalog\CatalogSemanticIndex;
use App\Services\Chat\Contracts\EscalationTarget;
use App\Services\Kb\Contracts\KbSource;
use App\Services\Notifications\Contracts\EscalationNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Одна команда на вопрос «почему бот молчит».
 *
 * Отвечает по порядку убывания вероятности: жив ли ключ, включено ли
 * маскирование ПДн, есть ли в базе фрагменты, той ли они модели, работает ли
 * очередь. Каждая строка — либо «ок», либо конкретное указание, что чинить.
 */
class AiKbDoctor extends Command
{
    protected $signature = 'ai:kb-doctor {--probe : Сделать пробный вызов модели и эмбеддингов (стоит копейки)}';

    protected $description = 'Проверить готовность ИИ-ассистента к работе';

    private bool $failed = false;

    public function handle(LlmClient $llm): int
    {
        $this->section('Конфигурация');
        $this->configuration($llm);

        $this->section('Ключ шлюза');
        $this->gatewayKey();

        $this->section('База знаний');
        $this->knowledgeBase($llm);

        $this->section('Смысловой поиск');
        $this->semanticSearch($llm);

        $this->section('Очередь');
        $this->queue();

        $this->section('Эскалация');
        $this->escalation();

        if ($this->option('probe')) {
            $this->section('Пробные вызовы');
            $this->probe($llm);
        } else {
            $this->newLine();
            $this->line('<fg=gray>Пробные вызовы модели пропущены. Добавьте --probe, чтобы проверить и их.</>');
        }

        $this->newLine();

        if ($this->failed) {
            $this->error('Есть проблемы — см. строки со знаком ✗ выше.');

            return self::FAILURE;
        }

        $this->info('Ассистент готов к работе.');

        return self::SUCCESS;
    }

    private function configuration(LlmClient $llm): void
    {
        /*
         * Выключателей два, и «почему бот молчит» зависит от того, какой
         * из них сработал: выключатель в админке владелец снимет сам, а строку
         * в .env он не найдёт никогда. Поэтому называем виновника.
         */
        $config = app(AssistantConfig::class);

        $this->check(
            $config->enabled(),
            'Ассистент включён',
            $config->disabledByAdmin()
                ? 'Выключен в админке: «ИИ бот» → «Настройки бота»'
                : 'Выключен аварийно: AI_AGENT_ENABLED=false в .env',
        );

        $key = (string) config('ai_support.gateway.key');
        $fake = (bool) config('ai_support.embedding.fake');

        if ($fake) {
            $this->warn('  ! Режим заглушки (AI_EMBEDDING_FAKE=true): модель не вызывается, поиск бессмысленен.');
        }

        $this->check($key !== '' || $fake, 'Ключ шлюза задан', 'AI_GATEWAY_KEY пуст');

        $this->info(sprintf('  · модель диалога   %s', $llm->chatModel()));
        $this->info(sprintf('  · эмбеддинги       %s, %d измерений', $llm->embeddingModel(), $llm->embeddingDimensions()));
        $this->info(sprintf('  · порог поиска     %.2f', (float) config('ai_support.knowledge_base.min_score')));
    }

    /**
     * Настройки ключа лежат на стороне шлюза и меняются в его панели, вне
     * нашего кода. Поэтому именно спрашиваем, а не полагаемся на конфиг:
     * маскирование ПДн может оказаться выключенным без единой правки у нас.
     */
    private function gatewayKey(): void
    {
        $key = (string) config('ai_support.gateway.key');

        if ($key === '') {
            $this->skip('Ключ не задан — пропускаем.');

            return;
        }

        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->timeout(15)
                ->get(config('ai_support.gateway.base_url').'/aitunnel/key');
        } catch (Throwable $e) {
            $this->bad('Шлюз недоступен: '.$e->getMessage());

            return;
        }

        if (! $response->successful()) {
            $this->bad(sprintf('Шлюз ответил HTTP %d — ключ отозван или неверен.', $response->status()));

            return;
        }

        $body = $response->json();

        $this->ok(sprintf('Ключ принят, имя «%s»', $body['name'] ?? '—'));

        $budget = $body['budget'] ?? null;

        if (is_array($budget)) {
            $this->info(sprintf(
                '  · бюджет           %.2f из %.2f ₽, сброс %s',
                (float) ($budget['remaining'] ?? 0),
                (float) ($budget['initial'] ?? 0),
                (string) ($budget['reset_interval'] ?? '—'),
            ));
        } else {
            $this->warn('  ! Бюджет на ключе не задан — расход ничем не ограничен.');
        }

        $mode = $body['pii']['mode'] ?? null;

        // Маскирование — то, что отделяет «мы не передаём персональные данные»
        // от обратного. Отдельная строка, потому что молча выключенный режим
        // выглядит ровно так же, как включённый.
        $this->check(
            $mode === 'mask',
            'Маскирование ПДн включено (режим mask)',
            $mode === 'block'
                ? 'Режим block: любой телефон в сообщении уронит ответ. Нужен mask.'
                : 'Маскирование ВЫКЛЮЧЕНО — ПДн уходят провайдеру как есть. Панель → Ключи.',
        );

        if ($mode !== null) {
            $types = $body['pii']['types'] ?? null;
            $this->info('  · типы ПДн         '.($types === null ? 'все' : implode(', ', (array) $types)));
        }
    }

    private function knowledgeBase(LlmClient $llm): void
    {
        $table = (string) config('ai_support.knowledge_base.table');

        $rows = DB::table($table)
            ->selectRaw('source, count(*) as chunks, sum(embedding is null) as no_vector,
                         min(dim) as min_dim, max(dim) as max_dim,
                         group_concat(distinct embed_model) as models,
                         max(updated_at) as updated')
            ->groupBy('source')
            ->get();

        if ($rows->isEmpty()) {
            $this->bad('База знаний пуста. Запустите: php artisan ai:kb-reindex');

            return;
        }

        $total = 0;

        foreach ($rows as $row) {
            $total += (int) $row->chunks;

            $this->ok(sprintf(
                '%s: %d фрагм., обновлено %s',
                $row->source,
                $row->chunks,
                $row->updated,
            ));

            if ((int) $row->no_vector > 0) {
                $this->bad(sprintf('  %d фрагментов без вектора — переиндексируйте.', $row->no_vector));
            }

            // Разные модели или размерности в одной таблице — самая коварная
            // поломка: поиск не падает, он просто перестаёт находить нужное,
            // потому что векторы из разных пространств несравнимы.
            $models = array_filter(explode(',', (string) $row->models));

            if (count($models) > 1) {
                $this->bad('  Векторы от разных моделей: '.implode(', ', $models).'. Нужна полная переиндексация.');
            } elseif ($models !== [] && $models[0] !== $llm->embeddingModel()) {
                $this->bad(sprintf(
                    '  Векторы посчитаны моделью %s, а в конфиге %s. Нужна полная переиндексация.',
                    $models[0],
                    $llm->embeddingModel(),
                ));
            }

            if ((int) $row->min_dim !== (int) $row->max_dim) {
                $this->bad(sprintf('  Разная размерность: от %d до %d.', $row->min_dim, $row->max_dim));
            } elseif ((int) $row->min_dim !== $llm->embeddingDimensions()) {
                $this->bad(sprintf(
                    '  Размерность в базе %d, в конфиге %d. Нужна полная переиндексация.',
                    $row->min_dim,
                    $llm->embeddingDimensions(),
                ));
            }
        }

        $this->info(sprintf('  · всего            %d фрагментов, ~%.1f МБ векторов',
            $total, $total * $llm->embeddingDimensions() * 4 / 1048576));

        $indexed = $rows->pluck('source')->all();
        $registered = [];

        foreach (AiSupportServiceProvider::sources() as $source) {
            $registered[] = $source->name();

            if (in_array($source->name(), $indexed, true)) {
                continue;
            }

            /*
             * Зарегистрирован, но ни разу не проиндексирован. Иначе обнаруживается
             * только тем, что бот не знает половины ответов.
             *
             * Пустой источник — не поломка: пока заказчик не написал ни одной
             * статьи, индексировать нечего, и крестик тут приучил бы не читать
             * доктора. Но строка остаётся — пустая база и есть главный риск.
             */
            if ($this->isEmptySource($source)) {
                $this->skip(sprintf('Источник «%s» пуст — индексировать нечего.', $source->name()));
            } else {
                $this->bad(sprintf('Источник «%s» зарегистрирован, но не проиндексирован.', $source->name()));
            }
        }

        /*
         * Обратный случай, и он опаснее: фрагменты есть, а источника в коде
         * больше нет. Такие сироты никем не обновляются, но продолжают
         * всплывать в выдаче наравне со свежими — бот будет уверенно отвечать
         * по данным, которых уже нет ни в одном источнике.
         *
         * Штатный prune их не убирает: он перебирает зарегистрированные
         * источники и про исчезнувший попросту не знает.
         */
        foreach (array_diff($indexed, $registered) as $orphan) {
            $this->bad(sprintf(
                'Источник «%s» есть в базе, но не зарегистрирован в коде — фрагменты осиротели. '
                .'Убрать: ai:kb-forget %s',
                $orphan,
                $orphan,
            ));
        }
    }

    private function isEmptySource(KbSource $source): bool
    {
        foreach ($source->documents() as $document) {
            return false;
        }

        return true;
    }

    /**
     * Зеркало каталога: собрано ли, тем ли, и не устарело ли.
     *
     * Проверяется здесь, а не глазами в Meilisearch, потому что все три
     * поломки зеркала МОЛЧАЛИВЫ: без эмбеддера гибрид отвечает ошибкой
     * на каждый запрос, с векторами другой модели — находит не то, а отставший
     * счётчик документов значит, что часть каталога боту просто не видна.
     * Ни одна из них не видна ни в логе, ни в ответе бота.
     */
    private function semanticSearch(LlmClient $llm): void
    {
        $index = app(CatalogSemanticIndex::class);

        $active = (int) DB::table('products')->where('is_active', true)->count();
        $documents = $index->count();

        $rows = DB::table('product_embeddings')
            ->selectRaw('count(*) as total, group_concat(distinct model) as models,
                         min(dimensions) as min_dim, max(dimensions) as max_dim')
            ->first();

        $embedded = (int) ($rows->total ?? 0);

        if ($embedded === 0) {
            /*
             * Предупреждение, а не ошибка: до первого прогона команды бот ищет
             * товары по словам и покупателю отвечает. Красная строка здесь
             * означала бы «ассистент не готов», что неправда.
             */
            $this->warn('  ! Векторов каталога нет — поиск идёт по словам, без смысла.');
            $this->skip('  Собрать зеркало: php artisan ai:catalog-embed');

            return;
        }

        $this->ok(sprintf('Зеркало «%s»: %d документов, векторов посчитано %d, активных товаров %d',
            $index->name(), $documents, $embedded, $active));

        // Разошлись — значит часть каталога боту не видна вовсе: либо ночной
        // прогон не доходил, либо документы не доехали до индекса.
        if (abs($documents - $active) > max(10, (int) ($active * 0.02))) {
            $this->bad(sprintf('  Зеркало разошлось с каталогом на %d товаров — нужен прогон ai:catalog-embed.',
                abs($documents - $active)));
        }

        $this->check(
            $index->embedderDeclared(),
            'Эмбеддер объявлен — гибридный поиск включён',
            'Эмбеддер в зеркале НЕ объявлен: поиск по смыслу не работает, хотя векторы есть. '
                .'Лечится прогоном ai:catalog-embed.',
        );

        $models = array_filter(explode(',', (string) ($rows->models ?? '')));

        if (count($models) > 1) {
            $this->bad('  Векторы от разных моделей: '.implode(', ', $models).'. Нужен ai:catalog-embed --force.');
        } elseif ($models !== [] && $models[0] !== $llm->embeddingModel()) {
            $this->bad(sprintf('  Векторы посчитаны моделью %s, а в конфиге %s. Нужен ai:catalog-embed --force.',
                $models[0], $llm->embeddingModel()));
        }

        if ((int) ($rows->min_dim ?? 0) !== $llm->embeddingDimensions()) {
            $this->bad(sprintf('  Размерность векторов %d, в конфиге %d. Нужен ai:catalog-embed --force.',
                (int) ($rows->min_dim ?? 0), $llm->embeddingDimensions()));
        }
    }

    private function queue(): void
    {
        $connection = 'redis-assistant';
        $queue = (string) config("queue.connections.{$connection}.queue", 'assistant');

        try {
            $size = Queue::connection($connection)->size($queue);
        } catch (Throwable $e) {
            $this->bad("Очередь {$connection} недоступна: ".$e->getMessage());

            return;
        }

        $this->ok(sprintf('Очередь %s/%s доступна, задач в ожидании: %d', $connection, $queue, $size));

        // Инвариант, нарушение которого проявляется как дубли платных вызовов:
        // джоба должна умирать раньше, чем очередь решит, что её никто не взял.
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

        $this->check(
            $retryAfter >= 300,
            sprintf('retry_after %d с (джоба < воркер 240 < retry_after)', $retryAfter),
            sprintf('retry_after %d с — мало, будут дубли вызовов. Нужно ≥ 300.', $retryAfter),
        );
    }

    /**
     * Есть ли кому получить сигнал «в чате ждут человека».
     *
     * Самая тихая из возможных аварий: почта в настройке есть, строки
     * в `users` с такой почтой нет — уведомление в колокольчике уходит
     * в никуда, и ошибки не будет нигде. Поэтому проверяем пересечение,
     * а не «список не пуст».
     */
    private function escalation(): void
    {
        $staff = app(EscalationTarget::class);

        $emails = $staff->managerEmails();

        $this->check(
            $emails !== [],
            'Письмо менеджерам уйдёт на: '.implode(', ', $emails),
            'Некому писать: пусты и general.manager_emails, и general.filament_admin_emails',
        );

        $recipients = collect($staff->panelRecipients());

        $this->check(
            $recipients->isNotEmpty(),
            sprintf('Уведомление в админке увидят %d чел.: %s', $recipients->count(), $recipients->pluck('email')->implode(', ')),
            'Ни у одной почты из general.filament_admin_emails нет пользователя — уведомления в админке уйдут в никуда',
        );

        $push = app(EscalationNotifier::class);

        $push->isConfigured()
            ? $this->ok('Пуш в мессенджер настроен')
            : $this->skip('Пуш в мессенджер выключен: нет MAX_BOT_TOKEN/MAX_BOT_CHAT_ID (письмо и админка работают)');

        $cooldown = (int) config('ai_support.escalation.notify_cooldown_minutes');

        $this->info($cooldown > 0
            ? sprintf('  · не чаще одного сигнала о диалоге в %d мин', $cooldown)
            : '  · кулдаун выключен: сигнал уйдёт на каждую эскалацию');
    }

    private function probe(LlmClient $llm): void
    {
        try {
            $started = microtime(true);
            $batch = $llm->embed(['проверка связи'], mode: 'doc');

            $this->ok(sprintf(
                'Эмбеддинги: %d измерений за %.0f мс, %.4f ₽',
                count($batch->first()),
                (microtime(true) - $started) * 1000,
                $batch->costRub,
            ));
        } catch (Throwable $e) {
            $this->bad('Эмбеддинги: '.$e->getMessage());
        }

        try {
            $started = microtime(true);
            $result = $llm->chat(
                'Ты отвечаешь одним словом.',
                [['role' => 'user', 'content' => 'Скажи «ок».']],
                maxTokens: 16,
                sessionId: 'kb-doctor',
            );

            $this->ok(sprintf(
                'Диалог: «%s» за %.0f мс, %d/%d токенов, %.4f ₽',
                mb_strimwidth(trim($result->content), 0, 40, '…'),
                (microtime(true) - $started) * 1000,
                $result->inputTokens,
                $result->outputTokens,
                $result->costRub,
            ));
        } catch (Throwable $e) {
            $this->bad('Диалог: '.$e->getMessage());
        }
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");
    }

    private function check(bool $condition, string $ok, string $error): void
    {
        $condition ? $this->ok($ok) : $this->bad($error);
    }

    private function ok(string $message): void
    {
        $this->line("  <info>✓</info> {$message}");
    }

    private function bad(string $message): void
    {
        $this->failed = true;
        $this->line("  <fg=red>✗</> {$message}");
    }

    private function skip(string $message): void
    {
        $this->line("  <fg=gray>–</> {$message}");
    }
}
