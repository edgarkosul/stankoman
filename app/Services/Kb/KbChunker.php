<?php

namespace App\Services\Kb;

/**
 * Резка текста базы знаний на фрагменты под эмбеддинг.
 *
 * Стратегия: делим по `##`, крупные разделы дорезаем по `###`, дальше по
 * абзацам, в крайнем случае по предложениям и символам. H1 выбрасываем —
 * заголовок страницы и так лежит в крошках.
 *
 * Ключевая деталь, из-за которой поиск вообще работает: в текст каждого
 * фрагмента подставляется префикс из крошек и пути раздела. Кусок
 * «Оплата по счёту с расчётного счёта» сам по себе не отвечает на вопрос
 * «как платит юрлицо» — а с префиксом «Оплата › Оплата юридическим лицом»
 * отвечает. Префикс идёт в эмбеддинг, поэтому он не косметика.
 *
 * Порт `siteko/app/Services/Help/HelpArticleChunker.php` через kratonshop —
 * стратегия и форма фрагмента сохранены намеренно: там она обкатана на проде.
 */
final class KbChunker
{
    /** Порог в символах, выше которого раздел режется дальше. */
    public const MAX_CHARS = 1800;

    /**
     * @param  string  $locator  устойчивый адрес документа внутри источника
     *                           (`intertooler-page:dostavka-i-oplata`), из
     *                           которого собирается chunk_id. Публичный URL
     *                           сюда не годится: он меняется, а идентичность
     *                           фрагмента меняться не должна.
     * @param  list<string>  $breadcrumb
     * @return list<array{chunk_id: string, title: string, breadcrumb: list<string>, section_path: list<string>, text: string, chars: int}>
     */
    public function chunk(
        string $locator,
        string $title,
        array $breadcrumb,
        string $markdown,
        int $maxChars = self::MAX_CHARS,
    ): array {
        $chunks = [];

        foreach ($this->splitByLevel($markdown, '##') as [$h2, $body]) {
            $sectionPath = $h2 === null ? [] : [$h2];
            $body = $h2 === null ? $this->stripH1($body) : $body;

            $this->emit($chunks, $locator, $title, $breadcrumb, $sectionPath, $body, $maxChars);
        }

        return $chunks;
    }

    /**
     * Деление по заголовкам уровня $hashes. Первый элемент — то, что стоит
     * до первого заголовка (у него heading = null).
     *
     * @return list<array{0: string|null, 1: string}>
     */
    private function splitByLevel(string $markdown, string $hashes): array
    {
        $tokens = preg_split(
            '/^('.$hashes.'[^\n#].*)$/m',
            $markdown,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        ) ?: [$markdown];

        $out = [[null, $tokens[0]]];

        for ($i = 1; $i < count($tokens); $i += 2) {
            $out[] = [trim(ltrim($tokens[$i], '#')), $tokens[$i + 1] ?? ''];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $chunks
     * @param  list<string>  $breadcrumb
     * @param  list<string>  $sectionPath
     */
    private function emit(
        array &$chunks,
        string $locator,
        string $title,
        array $breadcrumb,
        array $sectionPath,
        string $text,
        int $maxChars,
    ): void {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        if (mb_strlen($text) <= $maxChars) {
            $this->addChunk($chunks, $locator, $title, $breadcrumb, $sectionPath, $text);

            return;
        }

        // Раздел большой — пробуем дорезать по подзаголовкам.
        $subs = $this->splitByLevel($text, '###');

        if (count($subs) > 1) {
            foreach ($subs as [$h3, $body]) {
                $path = $h3 === null ? $sectionPath : [...$sectionPath, $h3];
                $this->emit($chunks, $locator, $title, $breadcrumb, $path, $body, $maxChars);
            }

            return;
        }

        // Подзаголовков нет — делим по абзацам.
        $parts = $this->splitParagraphs($text, $maxChars);

        foreach ($parts as $j => $part) {
            $path = count($parts) > 1 ? [...$sectionPath, 'ч. '.($j + 1)] : $sectionPath;
            $this->addChunk($chunks, $locator, $title, $breadcrumb, $path, $part);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $chunks
     * @param  list<string>  $breadcrumb
     * @param  list<string>  $sectionPath
     */
    private function addChunk(
        array &$chunks,
        string $locator,
        string $title,
        array $breadcrumb,
        array $sectionPath,
        string $text,
    ): void {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        $prefix = implode(' > ', [...$breadcrumb, ...$sectionPath]);

        $chunks[] = [
            'chunk_id' => $locator.'#'.count($chunks),
            'title' => $title,
            'breadcrumb' => $breadcrumb,
            'section_path' => $sectionPath,
            'text' => $prefix."\n\n".$text,
            'chars' => mb_strlen($text),
        ];
    }

    /**
     * @return list<string>
     */
    private function splitParagraphs(string $text, int $maxChars): array
    {
        $paragraphs = array_values(array_filter(
            array_map('trim', preg_split('/\n\s*\n/', $text) ?: []),
            static fn (string $p): bool => $p !== '',
        ));

        $blocks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            foreach ($this->hardPieces($paragraph, $maxChars) as $piece) {
                if ($current !== '' && mb_strlen($current) + mb_strlen($piece) + 2 > $maxChars) {
                    $blocks[] = $current;
                    $current = $piece;
                } else {
                    $current = $current === '' ? $piece : $current."\n\n".$piece;
                }
            }
        }

        if ($current !== '') {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Абзац крупнее порога: дробим по строкам, затем по предложениям,
     * в крайнем случае по символам.
     *
     * @return list<string>
     */
    private function hardPieces(string $paragraph, int $maxChars): array
    {
        if (mb_strlen($paragraph) <= $maxChars) {
            return [$paragraph];
        }

        $pieces = [];

        foreach (explode("\n", $paragraph) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (mb_strlen($line) <= $maxChars) {
                $pieces[] = $line;

                continue;
            }

            foreach (preg_split('/(?<=[.!?])\s+/u', $line) ?: [] as $sentence) {
                $sentence = trim($sentence);

                while (mb_strlen($sentence) > $maxChars) {
                    $pieces[] = mb_substr($sentence, 0, $maxChars);
                    $sentence = mb_substr($sentence, $maxChars);
                }

                if ($sentence !== '') {
                    $pieces[] = $sentence;
                }
            }
        }

        return $pieces;
    }

    /** H1 убираем: заголовок страницы уже есть в крошках. */
    private function stripH1(string $markdown): string
    {
        return preg_replace('/^#[^\n#].*$/m', '', $markdown, 1) ?? $markdown;
    }
}
