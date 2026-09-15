<?php

use App\Services\Ai\Support\PiiRedactor;

$redact = fn (string $text): string => (new PiiRedactor)->redact($text);

it('берёт телефон слитной записью — то, чего не видит шлюз', function () use ($redact): void {
    // Замер 28.08.2026: «89211234567» шлюз не распознаёт вообще, хотя тот же
    // номер с пробелами берёт. В чате пишут именно слитно.
    expect($redact('Мой номер 89211234567'))->toBe('Мой номер [телефон]')
        ->and($redact('звоните +79211234567'))->toBe('звоните [телефон]');
});

it('берёт телефон без кода страны — вторая дыра шлюза', function () use ($redact): void {
    expect($redact('номер 921 123-45-67'))->toBe('номер [телефон]')
        ->and($redact('(921) 123-45-67'))->toBe('[телефон]');
});

it('берёт привычные форматы с восьмёркой и плюс семь', function () use ($redact): void {
    foreach (['+7 921 123-45-67', '8 (921) 123-45-67', '8-921-123-45-67', '8 921 123 45 67'] as $phone) {
        expect($redact("тел. {$phone}"))->toBe('тел. [телефон]');
    }
});

it('берёт почту', function () use ($redact): void {
    expect($redact('пишите sergey.petrov@example.com'))->toBe('пишите [email]')
        ->and($redact('почта Иван@почта.рф'))->toBe('почта [email]');
});

it('не путает ИНН с телефоном', function () use ($redact): void {
    // Десять цифр подряд — это ИНН, и правило «без кода страны» обязано
    // требовать разделители, иначе каждый ИНН стал бы телефоном.
    expect($redact('ИНН 7842349892'))->toBe('ИНН 7842349892');
});

it('не трогает цены, годы и артикулы', function () use ($redact): void {
    expect($redact('доставка от 50 000 руб. при заказе в 2026 году'))
        ->toBe('доставка от 50 000 руб. при заказе в 2026 году')
        ->and($redact('артикул 3 08 01 012'))->toBe('артикул 3 08 01 012')
        ->and($redact('компрессор ВК-J 15/10 TG'))->toBe('компрессор ВК-J 15/10 TG');
});

it('прячет длинные цифровые серии целиком, не откусывая от них телефон', function () use ($redact): void {
    // Номер счёта содержит подстроку, похожую на телефон. Без границ по цифрам
    // регексп вырезал бы её из середины и оставил нечитаемый огрызок.
    expect($redact('счёт 40817810155867289990'))->toBe('счёт [номер]');
});

it('чистит почту раньше, чем длинные цифры', function () use ($redact): void {
    // Иначе от «ivan1234567890@mail.ru» осталось бы «ivan[номер]@mail.ru»,
    // и по остатку уже не видно, что это был адрес.
    expect($redact('ivan1234567890@mail.ru'))->toBe('[email]');
});

it('справляется с реальной фразой из чата, где шлюз берёт не всё', function () use ($redact): void {
    $result = $redact('Здравствуйте! Это Сергей, мой номер 89211234567, почта s@k.ru, ИНН 7842349892');

    expect($result)->toBe('Здравствуйте! Это Сергей, мой номер [телефон], почта [email], ИНН 7842349892');
});

it('чистит историю, не ломая структуру, и не трогает написанное ботом', function (): void {
    $result = (new PiiRedactor)->redactIncoming([
        ['role' => 'user', 'content' => 'звоните 89211234567', PiiRedactor::ORIGIN => PiiRedactor::ORIGIN_VISITOR],
        // Бот продиктовал контакты магазина. На следующем ходу они обязаны
        // вернуться к нему целыми — иначе он скопирует заглушку покупателю,
        // как в диалоге 17 (13.09.2026).
        ['role' => 'assistant', 'content' => 'Пишите на sales@intertooler.ru', PiiRedactor::ORIGIN => PiiRedactor::ORIGIN_BOT],
        ['role' => 'tool', 'tool_call_id' => 'x', 'content' => 'тел. +7 (900) 246-86-60', PiiRedactor::ORIGIN => PiiRedactor::ORIGIN_BOT],
    ]);

    expect($result[0]['content'])->toBe('звоните [телефон]')
        ->and($result[1]['content'])->toBe('Пишите на sales@intertooler.ru')
        ->and($result[2]['content'])->toBe('тел. +7 (900) 246-86-60')
        ->and($result[2]['tool_call_id'])->toBe('x');
});

it('чистит реплику живого менеджера, хотя она едет под ролью assistant', function (): void {
    $result = (new PiiRedactor)->redactIncoming([
        ['role' => 'assistant', 'content' => 'Это Пётр, мой номер 89219998877', PiiRedactor::ORIGIN => PiiRedactor::ORIGIN_OPERATOR],
    ]);

    expect($result[0]['content'])->toBe('Это Пётр, мой номер [телефон]');
});

it('чистит запись без пометки происхождения — забыть пометку безопасно', function (): void {
    // Лишняя заглушка в тексте бота — косметика. Непрочищенная реплика
    // человека в шлюзе — утечка. Ошибаться надо в первую сторону.
    $result = (new PiiRedactor)->redactIncoming([
        ['role' => 'assistant', 'content' => 'звоните 89211234567'],
    ]);

    expect($result[0]['content'])->toBe('звоните [телефон]');
});

it('снимает пометку происхождения перед отправкой в шлюз', function (): void {
    $result = (new PiiRedactor)->withoutOrigin([
        ['role' => 'user', 'content' => 'вопрос', PiiRedactor::ORIGIN => PiiRedactor::ORIGIN_VISITOR],
    ]);

    expect($result[0])->toBe(['role' => 'user', 'content' => 'вопрос']);
});

it('умеет отвечать, было ли что чистить', function (): void {
    $redactor = new PiiRedactor;

    expect($redactor->containsPii('мой телефон 89211234567'))->toBeTrue()
        ->and($redactor->containsPii('сколько стоит доставка'))->toBeFalse();
});

it('отличает контакт от просто длинного числа', function (): void {
    $redactor = new PiiRedactor;

    expect($redactor->containsContact('моя почта maks-bubonov@list.ru'))->toBeTrue()
        ->and($redactor->containsContact('звоните 89211234567'))->toBeTrue()
        ->and($redactor->containsContact('номер 921 123-45-67'))->toBeTrue()
        // Артикул — не повод показывать форму контактов, хотя редактор
        // его и прячет.
        ->and($redactor->containsContact('артикул 123456789012'))->toBeFalse()
        ->and($redactor->containsPii('артикул 123456789012'))->toBeTrue()
        ->and($redactor->containsContact('сколько стоит доставка в Ярославль'))->toBeFalse();
});
