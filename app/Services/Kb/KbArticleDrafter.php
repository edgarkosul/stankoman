<?php

namespace App\Services\Kb;

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Support\PiiRedactor;
use App\Services\Kb\Data\KbArticleDraft;
use App\Services\Kb\Data\KbHit;

/**
 * Черновик статьи по группе вопросов покупателей.
 *
 * Единственный вызов модели, без инструментов: контекст собран заранее —
 * формулировки вопросов, ответы менеджеров и фрагменты базы, найденные
 * по центру группы.
 *
 * **Главное правило здесь — не «напиши хорошо», а «не выдумай».** Всё, что
 * бот сочинит, могут опубликовать не глядя, и оно станет для бота фактом
 * о магазине: ошибка, прошедшая круг, возвращается уверенным ответом
 * покупателю. Поэтому модель обязана либо опираться на выданные материалы,
 * либо честно сказать, чего не хватает, — и второе для этого экрана ценнее
 * первого: список «нужен факт от вас» и есть работа того, кто пишет базу.
 */
final class KbArticleDrafter
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly PiiRedactor $redactor,
        private readonly int $maxTokens,
        /**
         * Ниже этого порога фрагмент считается не найденным.
         *
         * Тот же порог, по которому бот решает, что ответа в базе нет, и стоит
         * он здесь по той же причине: слабое совпадение — это не «немного
         * полезно», а правдоподобный на вид контекст, на котором строится
         * неверный ответ. Пустые материалы честнее случайных: на них модель
         * обязана сказать «не знаю», а на случайных — начнёт пересказывать.
         */
        private readonly float $minScore,
        /** Название магазина — для системного промпта. */
        private readonly string $shopName = '',
    ) {}

    /**
     * @param  list<string>  $questions  формулировки покупателей, самая типичная первой
     * @param  list<KbHit>  $hits  что нашлось в базе по этой группе
     * @param  list<string>  $answers  как на это отвечали ЖИВЫЕ менеджеры —
     *                                 ответы, помеченные ими «в базу знаний».
     *                                 Самый ценный материал из трёх: база
     *                                 по этой группе пуста по определению,
     *                                 а здесь готовый ответ магазина своими
     *                                 словами.
     */
    public function draft(array $questions, array $hits = [], array $answers = []): KbArticleDraft
    {
        $questions = array_values(array_filter(array_map('trim', $questions), static fn (string $q): bool => $q !== ''));

        if ($questions === []) {
            return new KbArticleDraft('', []);
        }

        /*
         * Вопрос покупателя уходит в шлюз, а маскирование ПДн на ключе
         * закрывает не всё (телефон слитно, имя без отчества, адрес
         * прописью). Чистим до вызова, как перед любым chat() и embed().
         */
        $questions = array_map(fn (string $q): string => $this->redactor->redact($q), $questions);

        /*
         * Ответы менеджеров чистим так же, как вопросы, и по той же причине:
         * они уходят в шлюз. Здесь это даже нужнее — менеджер пишет живому
         * человеку и называет его по имени, поминает номер заказа и телефон,
         * с которого звонил.
         */
        $answers = array_values(array_filter(array_map(
            fn ($answer): string => $this->redactor->redact(trim((string) $answer)),
            $answers,
        ), static fn (string $a): bool => $a !== ''));

        $hits = array_values(array_filter(
            $hits,
            fn (KbHit $hit): bool => $hit->score >= $this->minScore,
        ));

        $result = $this->llm->chat(
            system: $this->systemPrompt(),
            messages: [['role' => 'user', 'content' => $this->userPrompt($questions, $hits, $answers)]],
            maxTokens: $this->maxTokens,
        );

        $parsed = $this->parse($result->content);

        $title = $parsed['title'] !== '' ? $parsed['title'] : KbArticleTitle::fromQuestion($questions[0]);

        return new KbArticleDraft(
            title: KbArticleTitle::fromQuestion($title),
            paragraphs: $parsed['paragraphs'],
            missingFacts: $parsed['missing_facts'],
            sources: $hits,
            costRub: $result->costRub,
            model: $this->llm->chatModel(),
        );
    }

    private function systemPrompt(): string
    {
        $shop = $this->shopName !== '' ? ' «'.$this->shopName.'»' : '';

        return <<<PROMPT
        Ты помогаешь администратору интернет-магазина промышленного оборудования{$shop}
        писать статью для базы знаний, из которой отвечает чат-бот магазина.

        ЧТО ТЫ ПОЛУЧАЕШЬ
        Вопросы покупателей об одном и том же и материалы двух видов: фрагменты уже
        написанных страниц и статей магазина и — если есть — ОТВЕТЫ МЕНЕДЖЕРОВ на эти
        вопросы. Может не быть ни того, ни другого.

        Ответы менеджеров — полноценный материал, и самый весомый из двух: это то, что
        магазин уже сказал живому покупателю своими словами. Факты из них брать МОЖНО
        и НУЖНО, правило ниже распространяется на них как на материалы, а не как
        на догадки. Обобщай: обращения по имени, номера заказов и всё, что относится
        к одному разговору, в статью не переносится.

        ГЛАВНОЕ ПРАВИЛО
        Факты берутся ТОЛЬКО из материалов. Сроки, цены, проценты, адреса, телефоны,
        названия компаний, условия гарантии и доставки, пороги сумм — если этого нет
        в материалах, ты не имеешь права это написать. Ни приблизительно, ни «обычно»,
        ни «как правило». Статью прочитает бот и повторит покупателю как факт о магазине.

        Само НАЛИЧИЕ услуги, скидки или условия — тоже факт. Если в материалах не сказано,
        что рассрочка есть, писать «мы предоставляем рассрочку» нельзя: напиши
        [уточнить: есть ли рассрочка] и вынеси это в missing_facts.

        Чего не хватает — перечисли в missing_facts вопросом к администратору
        («какой срок гарантии на станки?»). В самом тексте на месте недостающего
        факта оставь пометку в квадратных скобках: [уточнить: срок гарантии].
        Пустой missing_facts допустим только если материалы отвечают на вопрос целиком.

        КАК ПИСАТЬ
        - обращение на «вы», спокойно и по делу, без рекламы и без извинений;
        - 2–5 коротких абзацев, каждый — законченная мысль;
        - обычный текст: без markdown, без заголовков, без списков и эмодзи;
        - не пересказывай вопрос покупателя, сразу отвечай;
        - не пиши о себе, о боте, о базе знаний и о том, откуда взяты сведения;
        - заголовок — как спрашивает покупатель, без вопросительного знака,
          до 100 знаков: «Можно ли забрать заказ самовывозом».

        ОТВЕТ
        Верни ТОЛЬКО JSON, без пояснений и без ```:
        {"title": "...", "paragraphs": ["...", "..."], "missing_facts": ["...", "..."]}
        PROMPT;
    }

    /**
     * @param  list<string>  $questions
     * @param  list<KbHit>  $hits
     * @param  list<string>  $answers
     */
    private function userPrompt(array $questions, array $hits, array $answers = []): string
    {
        $parts = ['ВОПРОСЫ ПОКУПАТЕЛЕЙ (об одном и том же, разными словами):'];

        foreach ($questions as $question) {
            $parts[] = '- '.$question;
        }

        $parts[] = '';

        /*
         * Ответы менеджеров идут ПЕРВЫМИ и с прямым указанием опираться
         * на них: это не «похожий материал из базы», а то, что магазин уже
         * ответил живому покупателю. Если поставить их после материалов
         * базы, модель пересказывает базу, а живой ответ роняет в хвост.
         */
        if ($answers !== []) {
            $parts[] = 'КАК НА ЭТО ОТВЕЧАЛИ МЕНЕДЖЕРЫ МАГАЗИНА:';

            foreach ($answers as $answer) {
                $parts[] = '- '.$answer;
            }

            $parts[] = '';
            $parts[] = 'Это ответы магазина своими словами, и статья строится на них.';
            $parts[] = 'Обобщи их до правила, годного любому покупателю: убери обращения';
            $parts[] = 'по имени, номера заказов и всё, что относится к одному разговору.';
            $parts[] = 'Противоречат друг другу — возьми более полный, а расхождение';
            $parts[] = 'вынеси в missing_facts.';
            $parts[] = '';
        }

        if ($hits === [] && $answers !== []) {
            /*
             * База пуста, но ответы менеджеров есть — это НЕ случай
             * «фактов нет». Запрет выдумывать остаётся, источник фактов
             * другой: то, что магазин уже сказал покупателю.
             *
             * У донора первый живой прогон на этом месте вернул пустой
             * черновик: промпт определял материалы как фрагменты страниц,
             * ответы менеджеров под определение не попадали, и модель
             * послушно промолчала.
             */
            $parts[] = 'МАТЕРИАЛОВ В БАЗЕ НЕТ — пиши по ответам менеджеров выше.';
            $parts[] = 'Ничего сверх них не добавляй: чего там нет, того не знаешь.';
            $parts[] = 'Недостающее перечисли в missing_facts.';

            return implode("\n", $parts);
        }

        if ($hits === []) {
            /*
             * Пустые материалы — не ошибка, а самый частый случай: группа
             * попала в «Пробелы» именно потому, что ответа в базе нет.
             * Сказать об этом прямо надёжнее, чем оставить пустой раздел:
             * молчание модель склонна заполнять сама.
             */
            $parts[] = 'МАТЕРИАЛЫ: ничего подходящего в базе магазина не нашлось.';
            $parts[] = 'Значит фактического ответа у тебя нет, и выдумывать его запрещено.';
            $parts[] = 'Составь ЗАГОТОВКУ статьи: по абзацу на каждую сторону вопроса,';
            $parts[] = 'который задают покупатели, а на месте каждого недостающего факта —';
            $parts[] = 'пометка вида [уточнить: …]. Администратору должно остаться';
            $parts[] = 'вписать факты в готовый текст, а не писать статью с нуля.';
            $parts[] = 'Всё, что он должен вписать, перечисли и в missing_facts.';

            return implode("\n", $parts);
        }

        $parts[] = 'МАТЕРИАЛЫ ИЗ БАЗЫ МАГАЗИНА:';

        foreach ($hits as $i => $hit) {
            $parts[] = '';
            $parts[] = '['.($i + 1).'] '.($hit->path() !== '' ? $hit->path() : $hit->title);
            $parts[] = $hit->text;
        }

        return implode("\n", $parts);
    }

    /**
     * Разбор ответа модели.
     *
     * JSON обязателен промптом, но не гарантирован им — ровно тот случай,
     * когда «промпт задаёт норму, а гарантирует её код». Модель то обернёт
     * ответ в ```json, то напишет абзацы обычным текстом. Второе не провал:
     * текст статьи в нём есть, и терять его из-за формата глупо.
     *
     * @return array{title: string, paragraphs: list<string>, missing_facts: list<string>}
     */
    private function parse(string $content): array
    {
        $content = trim($content);

        if ($content === '') {
            return ['title' => '', 'paragraphs' => [], 'missing_facts' => []];
        }

        $json = $this->extractJson($content);

        if ($json !== null) {
            return [
                'title' => $this->text($json['title'] ?? ''),
                'paragraphs' => $this->lines($json['paragraphs'] ?? []),
                'missing_facts' => $this->lines($json['missing_facts'] ?? []),
            ];
        }

        // Ответ обычным текстом: абзацы разделены пустой строкой.
        $paragraphs = $this->lines(preg_split('/\n\s*\n/u', $content) ?: []);

        return ['title' => '', 'paragraphs' => $paragraphs, 'missing_facts' => []];
    }

    /** @return array<string, mixed>|null */
    private function extractJson(string $content): ?array
    {
        // ```json … ``` — самая частая обёртка вопреки прямому запрету.
        if (preg_match('/```(?:json)?\s*(.+?)```/su', $content, $m) === 1) {
            $content = trim($m[1]);
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';
    }

    /**
     * @return list<string>
     */
    private function lines(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $lines = [];

        foreach ($value as $item) {
            $text = is_string($item) ? trim($item) : '';

            if ($text !== '') {
                $lines[] = $text;
            }
        }

        return $lines;
    }
}
