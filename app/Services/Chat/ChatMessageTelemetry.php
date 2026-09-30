<?php

namespace App\Services\Chat;

use App\Models\ChatMessage;

/**
 * Телеметрия ответа бота, переведённая на человеческий.
 *
 * Существует потому, что у ленты диалога два читателя с разными вопросами,
 * и один вид на двоих не работает.
 *
 * Менеджер спрашивает «откуда бот это взял». Ответ на его вопрос — НЕ урезанная
 * техническая сводка, а другая: имена инструментов ему ничего не говорят,
 * зато «смотрел карточку товара» объясняет, откуда взялись «24 месяца»,
 * которых нет ни в одной статье. И наоборот: пять цитат, три из которых —
 * куски одной страницы, он читает как «три раза одно и то же».
 *
 * Разработчик спрашивает «почему бот ошибся». Ему нужны ровно те подробности,
 * которые менеджеру мешают: с каким запросом ходили в поиск, какой фрагмент
 * с какой близостью вернулся, сколько это заняло.
 *
 * Здесь собран первый вид. Второй показывается как есть, из самой модели.
 */
final readonly class ChatMessageTelemetry
{
    /**
     * @param  list<string>  $toolPhrases  «смотрел карточку товара», «искал в базе знаний (2 раза)»
     * @param  list<array{title: string, url: ?string, chunks: int, score: float}>  $sources
     */
    private function __construct(
        public array $toolPhrases,
        public array $sources,
    ) {}

    public static function for(ChatMessage $message): self
    {
        return new self(
            self::phrases($message->toolCallsDetailed()),
            self::sources((array) ($message->citations ?? [])),
        );
    }

    /**
     * Имена инструментов — в то, что делал бы на его месте человек.
     *
     * Неизвестное имя показываем как есть, а не прячем: инструмент, который
     * забыли сюда вписать, должен бросаться в глаза, а не исчезать из
     * объяснения вместе со своим вкладом в ответ.
     *
     * @param  list<array{name: string, arguments: array<string, mixed>, ms: int}>  $calls
     * @return list<string>
     */
    private static function phrases(array $calls): array
    {
        $counts = [];

        foreach ($calls as $call) {
            $counts[$call['name']] = ($counts[$call['name']] ?? 0) + 1;
        }

        $phrases = [];

        foreach ($counts as $name => $count) {
            $phrase = match ($name) {
                'search_knowledge_base' => 'искал в базе знаний',
                'browse_categories' => 'смотрел разделы каталога',
                'search_products' => 'искал товары',
                'get_product' => 'смотрел карточку товара',
                'escalate_to_operator' => 'позвал менеджера',
                'request_contact' => 'предложил оставить контакты',
                default => $name,
            };

            $phrases[] = $count > 1 ? $phrase.' ('.$count.' раза)' : $phrase;
        }

        return $phrases;
    }

    /**
     * Цитаты, свёрнутые до документов.
     *
     * База знаний хранит не статьи, а их нарезку, и одна страница легко
     * занимает три места в выдаче из пяти. В подробностях это важно —
     * видно, какой именно кусок сработал; в объяснении это шум: править
     * всё равно пойдут статью целиком.
     *
     * Ключ — ссылка, а при её отсутствии заголовок: у статей базы знаний
     * своего адреса нет, и различать их можно только по названию.
     *
     * @param  list<array{chunk_id?: string, score?: float, title?: string, url?: ?string}>  $citations
     * @return list<array{title: string, url: ?string, chunks: int, score: float}>
     */
    private static function sources(array $citations): array
    {
        $sources = [];

        foreach ($citations as $citation) {
            $title = trim((string) ($citation['title'] ?? ''));
            $url = $citation['url'] ?? null;

            if ($title === '' && blank($url)) {
                continue;
            }

            $key = filled($url) ? 'u:'.$url : 't:'.$title;
            $score = (float) ($citation['score'] ?? 0);

            if (! isset($sources[$key])) {
                $sources[$key] = [
                    'title' => $title !== '' ? $title : (string) $url,
                    'url' => filled($url) ? (string) $url : null,
                    'chunks' => 0,
                    'score' => $score,
                ];
            }

            $sources[$key]['chunks']++;
            // Порядок задаёт первое вхождение — цитаты уже отсортированы
            // по близости, — но лучшую оценку по документу держим отдельно.
            $sources[$key]['score'] = max($sources[$key]['score'], $score);
        }

        return array_values($sources);
    }
}
