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

/*
 * С 09.10.2026 у чата есть канал deepseek — сам API DeepSeek, без маскирования
 * на шлюзе: паспорт, СНИЛС, карту и ФИО с отчеством теперь берёт и редактор.
 */

it('берёт паспорт — с разделителем между серией и номером', function () use ($redact): void {
    expect($redact('паспорт 4510 123456'))->toBe('паспорт [номер]')
        ->and($redact('серия 45 10 номер 123456'))->toBe('серия [номер]')
        ->and($redact('4510 № 123456 выдан'))->toBe('[номер] выдан')
        // Десять цифр подряд — ИНН компании или телефон без кода, не паспорт.
        ->and($redact('ИНН 4510123456'))->toBe('ИНН 4510123456');
});

it('берёт карту с пробелами и дефисами — по контрольной сумме, как шлюз', function () use ($redact): void {
    expect($redact('карта 4111 1111 1111 1111'))->toBe('карта [номер]')
        ->and($redact('5500-0000-0000-0004, срок 12/28'))->toBe('[номер], срок 12/28')
        ->and($redact('Amex 3782 822463 10005'))->toBe('Amex [номер]')
        // Контрольная сумма не сходится — не карта: дата, заказ, опечатка.
        ->and($redact('заказ 4111 1111 1111 1112'))->toBe('заказ 4111 1111 1111 1112')
        // Цены и годы — меньше 13 цифр.
        ->and($redact('41 900 руб. до 2026 года, артикул 4510 1234'))->toBe('41 900 руб. до 2026 года, артикул 4510 1234');
});

it('берёт СНИЛС', function () use ($redact): void {
    expect($redact('СНИЛС 123-456-789 01'))->toBe('СНИЛС [номер]')
        ->and($redact('снилс 123 456 789 01'))->toBe('снилс [номер]');
});

it('берёт ФИО с отчеством в любом порядке и падеже', function () use ($redact): void {
    expect($redact('Меня зовут Иван Петрович'))->toBe('Меня зовут [имя]')
        ->and($redact('Сидоров Иван Петрович, перезвоните'))->toBe('[имя], перезвоните')
        ->and($redact('передайте Анне Сергеевне'))->toBe('передайте [имя]')
        ->and($redact('для Ивановой Марии Петровны'))->toBe('для [имя]')
        ->and($redact('Лев Николаевич Толстой'))->toBe('[имя]')
        ->and($redact('Ильич Кузьмич'))->toBe('[имя]');
});

it('не берёт имя без отчества, одно отчество и обычный текст', function () use ($redact): void {
    // Как и шлюз: имя без отчества ложных срабатываний дало бы больше, чем пользы.
    expect($redact('Сергей Петров'))->toBe('Сергей Петров')
        // «Петрович» — ещё и магазин.
        ->and($redact('в Петровиче дешевле, Петрович рядом'))->toBe('в Петровиче дешевле, Петрович рядом')
        ->and($redact('цена 1 500 000 руб., артикул 1234-567890, 2024 год'))->toBe('цена 1 500 000 руб., артикул 1234-567890, 2024 год')
        ->and($redact('Станок сверлильный Корвет 46 с тисками'))->toBe('Станок сверлильный Корвет 46 с тисками')
        ->and($redact('Доставка по Москве и области'))->toBe('Доставка по Москве и области');
});

it('убирает из ответа бота обращение по заглушке имени', function (): void {
    expect(PiiRedactor::withoutNamePlaceholder('Здравствуйте, [имя]! Чем помочь?'))->toBe('Здравствуйте! Чем помочь?')
        ->and(PiiRedactor::withoutNamePlaceholder('[имя], подскажу по доставке.'))->toBe('Подскажу по доставке.')
        ->and(PiiRedactor::withoutNamePlaceholder("Спасибо, [имя].\n[имя], всё готово"))->toBe("Спасибо.\nВсё готово")
        ->and(PiiRedactor::withoutNamePlaceholder('Без имени.'))->toBe('Без имени.');
});

it('заглушка имени — не контакт', function (): void {
    expect((new PiiRedactor)->containsContact('Меня зовут Иван Петрович'))->toBeFalse()
        ->and((new PiiRedactor)->containsPii('Меня зовут Иван Петрович'))->toBeTrue();
});
