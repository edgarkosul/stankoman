<?php

use App\Services\Ai\Support\ToolMarkup;

it('срезает вызов инструмента, написанный текстом, и оставляет ответ', function (): void {
    // Замер bots 29.09.2026 — конец ответа как есть.
    $text = "Счёт выставим на юрлицо, оплата по безналу.\n\n"
        ."<｜DSML｜tool_calls>\n<｜DSML｜invoke name=\"request_contact\">\n"
        ."<｜DSML｜parameter name=\"topic\" string=\"true\">Счёт на компрессор</｜DSML｜parameter>\n"
        ."</｜DSML｜invoke>\n</｜DSML｜tool_calls>";

    expect(ToolMarkup::in($text))->toBeTrue()
        ->and(ToolMarkup::strip($text))->toBe('Счёт выставим на юрлицо, оплата по безналу.');
});

it('срезает вызов посреди текста, оборванный вызов, запись v4.1 и старую запись DeepSeek', function (): void {
    $middle = "Начало.\n\n<|DSML|tool_calls><|DSML|invoke name=\"t\"></|DSML|invoke></|DSML|tool_calls>\n\nКонец.";
    $cut = "Ответ.\n<｜DSML｜tool_calls>\n<｜DSML｜invoke name=\"search_products\">";
    $v41 = "Ответ.\n\n<｜DSML｜ calls>\n<｜DSML｜ invoke name=\"request_contact\">\n</｜DSML｜ invoke>\n</｜DSML｜ calls>\n\nЕщё.";
    $legacy = 'Ответ.<｜tool▁calls▁begin｜><｜tool▁call▁begin｜>function<｜tool▁sep｜>search<｜tool▁call▁end｜><｜tool▁calls▁end｜>';

    expect(ToolMarkup::strip($middle))->toBe("Начало.\n\nКонец.")
        ->and(ToolMarkup::strip($cut))->toBe('Ответ.')
        ->and(ToolMarkup::strip($legacy))->toBe('Ответ.')
        ->and(ToolMarkup::strip($v41))->toBe("Ответ.\n\nЕщё.")
        ->and(ToolMarkup::strip('<｜DSML｜tool_calls><｜DSML｜invoke name="t">'))->toBe('');
});

it('обычный текст с угловыми скобками и чертой не трогает', function (): void {
    $text = 'Резьба <M12|M14> — уточните. Давление: 8 | 10 бар, тег <b>жирный</b>.';

    expect(ToolMarkup::in($text))->toBeFalse()
        ->and(ToolMarkup::strip($text))->toBe($text);
});
