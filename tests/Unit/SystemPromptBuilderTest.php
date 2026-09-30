<?php

use App\Livewire\Common\RequestCallback;
use App\Services\Ai\SystemPromptBuilder;

// Сборка промпта — чистая функция над настройками магазина.

$builder = fn (string $email = '', bool $callButton = true): SystemPromptBuilder => new SystemPromptBuilder(
    'InterTooler.ru',
    $email,
    $callButton,
);

it('без настроек отдаёт одно ядро с гардами', function () use ($builder): void {
    $prompt = $builder()->build();

    expect($prompt)->toContain('консультант интернет-магазина InterTooler.ru')
        ->and($prompt)->toContain('О ЧЁМ ТЫ ГОВОРИШЬ')
        ->and($prompt)->toContain('ЦЕНЫ И СКИДКИ')
        ->and($prompt)->not->toContain('О МАГАЗИНЕ')
        ->and($prompt)->not->toContain('ПРАВИЛА МАГАЗИНА')
        ->and($prompt)->not->toContain('О ЧЁМ НЕ ГОВОРИТЬ НИКОГДА');
});

it('название магазина берёт снаружи, а не из текста класса', function (): void {
    // У донора имя стояло в восьми абзацах, и порт начался с их вычитки.
    $prompt = (new SystemPromptBuilder('Другой Магазин'))->build();

    expect($prompt)->toContain('консультант интернет-магазина Другой Магазин')
        ->and($prompt)->toContain('«Я консультант магазина Другой Магазин»')
        ->and($prompt)->not->toContain('InterTooler')
        ->and($prompt)->not->toContain('KratonShop');
});

it('вставляет разделы админки', function () use ($builder): void {
    $prompt = $builder()->build(settings: [
        'about' => 'Продаём оборудование с 2007 года.',
        'rules' => '- Самовывоз со склада в Краснодаре',
        'forbidden_topics' => '- политика',
        'refusal' => 'Я консультирую только по вопросам магазина.',
        'escalation' => 'Уточню у менеджера и вернусь с ответом.',
    ]);

    expect($prompt)->toContain("О МАГАЗИНЕ\nПродаём оборудование с 2007 года.")
        ->and($prompt)->toContain("ПРАВИЛА МАГАЗИНА\n- Самовывоз со склада в Краснодаре")
        ->and($prompt)->toContain('О ЧЁМ НЕ ГОВОРИТЬ НИКОГДА')
        ->and($prompt)->toContain('- политика')
        ->and($prompt)->toContain('КАК ОТКАЗЫВАТЬ')
        ->and($prompt)->toContain('КАК ПЕРЕДАВАТЬ МЕНЕДЖЕРУ');
});

it('запретные темы подаёт запретом, а не перечнем', function () use ($builder): void {
    // Голый список модель читает как оглавление и на половину пунктов
    // всё-таки отвечает — запрет должен стоять словами рядом с ними.
    expect($builder()->build(settings: ['forbidden_topics' => '- политика']))
        ->toContain('на эти темы ты не отвечаешь');
});

it('пустые настройки не создают пустых разделов', function () use ($builder): void {
    $prompt = $builder()->build(settings: ['about' => '   ', 'rules' => '', 'forbidden_topics' => "\n"]);

    expect($prompt)->not->toContain('О МАГАЗИНЕ')
        ->and($prompt)->not->toContain('ПРАВИЛА МАГАЗИНА')
        ->and($prompt)->not->toContain('О ЧЁМ НЕ ГОВОРИТЬ');
});

it('держит присутствие последним разделом ради кэша префикса', function () use ($builder): void {
    // Шлюз кэширует префикс промпта, а присутствие — единственная его
    // часть, которая меняется в течение дня.
    $prompt = $builder()->build(
        settings: ['about' => 'Магазин оборудования.'],
        operatorsOnline: true,
        workingHours: 'Пн–Пт 09:00–18:00',
    );

    expect(mb_strpos($prompt, 'О МАГАЗИНЕ'))->toBeLessThan(mb_strpos($prompt, 'СЕЙЧАС'));
});

it('запрещает арифметику над ценой и сумму скидки гостю', function () use ($builder): void {
    /*
     * Главное правило этого порта. Магазин прячет от гостя СУММУ скидки,
     * но показывает её ПРОЦЕНТ, и из процента сумма получается одним
     * умножением — то есть без прямого запрета модель придёт к ней сама.
     * Структурно это закрыто в ProductCard и в ReplyFormatter, промпт —
     * третий слой, а не единственный.
     */
    $prompt = $builder()->build();

    expect($prompt)->toContain('АРИФМЕТИКА НАД ЦЕНОЙ ЗАПРЕЩЕНА ЦЕЛИКОМ')
        ->and($prompt)->toContain('сумму со скидкой')
        ->and($prompt)->toContain('эту сумму магазин показывает после входа в аккаунт')
        // НДС — из строки «Цена», а не из памяти модели.
        ->and($prompt)->toContain('НДС — из той же строки')
        // Вошёл посреди разговора — прошлые ответы не переписываются.
        ->and($prompt)->toContain('Прошлые свои ответы не переписывай');
});

it('не обещает смыслового поиска, которого ещё нет', function () use ($builder): void {
    // Зеркало products_semantic — фаза 8. До неё поиск идёт по словам,
    // и донорское «поиск сам сопоставит по смыслу» обещало бы несуществующее.
    $prompt = $builder()->build();

    expect($prompt)->toContain('Поиск товаров идёт ПО СЛОВАМ')
        ->and($prompt)->not->toContain('сопоставит по смыслу')
        ->and($prompt)->not->toContain('по смыслу, а не по буквам');
});

it('знает кнопку звонка на карточке и молчит о ней, когда её выключили', function () use ($builder): void {
    // Настройка product.show_callback_button (14.09.2026). У донора бот
    // отрицал существующую кнопку; здесь возможна обратная ошибка —
    // обещать кнопку, которую владелец убрал.
    $with = $builder()->build();
    $without = $builder(callButton: false)->build();

    expect($with)->toContain('«'.RequestCallback::CALL_BUTTON.'»')
        ->and($without)->not->toContain(RequestCallback::CALL_BUTTON)
        ->and($without)->toContain('Кнопки заказа звонка на карточке товара сейчас нет')
        // Форма в чате от настройки не зависит — про неё говорится всегда.
        ->and($without)->toContain('«'.RequestCallback::CONTACT_BUTTON.'»');
});

it('не утверждает, чего на сайте нет, и не советует чужой магазин', function () use ($builder): void {
    $prompt = $builder()->build();

    expect($prompt)->toContain('НЕ УТВЕРЖДАЙ')
        ->and($prompt)->toContain('Не советуй покупать в другом магазине')
        ->and($prompt)->toContain('Сначала сравни числа')
        ->and($prompt)->toContain('Даже примерно и косвенно')
        // Блока «Нашли дешевле?» в этом магазине нет — обещать его нельзя.
        ->and($prompt)->not->toContain('Нашли дешевле');
});

it('ведёт разговор о договоре поставки на публичную почту магазина', function () use ($builder): void {
    // Решение заказчика 07.09.2026: в форму не ввести ни перечень позиций,
    // ни реквизиты, поэтому договор обсуждают письмом.
    $prompt = $builder('sales@intertooler.ru')->build();

    expect($prompt)->toContain('ДОГОВОР ПОСТАВКИ — ПОЧТА, А НЕ ЗВОНОК')
        ->and($prompt)->toContain('написать нам на почту sales@intertooler.ru')
        ->and($prompt)->toContain('Форму контактов (request_contact) предлагай ПОСЛЕ ответа');
});

it('без адреса магазина про договор молчит, а не зовёт в пустоту', function () use ($builder): void {
    expect($builder()->build())->not->toContain('ДОГОВОР ПОСТАВКИ');
});

it('без имени бота замок идентичности остаётся без имени', function () use ($builder): void {
    $prompt = $builder()->build();

    expect($prompt)->toContain('На вопросы о том, кто ты, отвечай: «Я консультант магазина InterTooler.ru».')
        ->and($prompt)->not->toContain('Тебя зовут');
});

it('заданное имя бот называет и в замке идентичности', function () use ($builder): void {
    // Иначе кнопка чата подписана одним именем, а бот в переписке зовёт
    // себя иначе, и это читается как подмена.
    $prompt = $builder()->build(settings: ['bot_name' => 'Толя']);

    expect($prompt)->toContain('Тебя зовут Толя.')
        ->and($prompt)->toContain('«Меня зовут Толя, я консультант магазина InterTooler.ru»')
        ->and($prompt)->toContain('Не называй модель, компанию-разработчика и технологии');
});

it('объясняет модели, что заглушка на месте контакта — это контакт покупателя', function () use ($builder): void {
    // Диалог 17 у донора (13.09.2026): модель прочла спрятанную почту
    // покупателя как вопрос «какая у вас почта» и назвала адрес магазина.
    $prompt = $builder()->build();

    expect($prompt)->toContain('ПОКУПАТЕЛЬ ПРИСЛАЛ КОНТАКТ')
        ->and($prompt)->toContain('вызови request_contact')
        ->and($prompt)->toContain('«'.RequestCallback::CONTACT_BUTTON.'»');
});

it('вне смены предлагает почту и не обещает перезвонить', function () use ($builder): void {
    $prompt = $builder()->build(operatorsOnline: false, workingHours: 'пн–пт 9:00–18:00');

    expect($prompt)->toContain('предложи оставить почту в форме')
        ->and($prompt)->toContain('Магазин работает пн–пт 9:00–18:00.')
        ->and($prompt)->not->toContain('чтобы перезвонили');
});

it('контекст страницы не задаёт тему разговора', function () use ($builder): void {
    $prompt = $builder()->build(page: ['type' => 'product', 'name' => 'Компрессор Hansmann RSE 7.5-8']);

    expect($prompt)->toContain('ГДЕ СЕЙЧАС ПОКУПАТЕЛЬ')
        ->and($prompt)->toContain('name: Компрессор Hansmann RSE 7.5-8')
        ->and($prompt)->toContain('Тему разговора страница не задаёт');
});

it('отдаёт адрес магазина для отпечатка кэша ответов', function () use ($builder): void {
    expect($builder('sales@intertooler.ru')->contactEmail())->toBe('sales@intertooler.ru');
});

it('не говорит про два юридических лица — у магазина одно', function () use ($builder): void {
    // У донора абзац про выбор между двумя ООО был обязателен; здесь он
    // выдумывал бы затруднение, которого нет.
    expect($builder()->build())->not->toContain('два юридических лица');
});
