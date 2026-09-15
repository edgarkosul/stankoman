<?php

use App\Models\ChatMessage;
use App\Providers\AiSupportServiceProvider;
use App\Services\Chat\ChatMessageTelemetry;
use Tests\TestCase;

uses(TestCase::class);

/** @param array<string, mixed> $attributes */
function telemetryAnswer(array $attributes = []): ChatMessage
{
    return (new ChatMessage)->forceFill(array_merge([
        'id' => 1,
        'role' => ChatMessage::ROLE_ASSISTANT,
        'body' => 'Гарантия 12 месяцев.',
        'stop_reason' => 'stop',
    ], $attributes));
}

it('переводит имена инструментов на человеческий', function (): void {
    $message = telemetryAnswer(['tool_calls' => [
        ['name' => 'get_product', 'arguments' => ['id' => 7], 'ms' => 12],
        ['name' => 'search_knowledge_base', 'arguments' => ['query' => 'гарантия'], 'ms' => 900],
        ['name' => 'request_contact', 'arguments' => ['topic' => 'счёт'], 'ms' => 1],
    ]]);

    expect(ChatMessageTelemetry::for($message)->toolPhrases)
        ->toBe(['смотрел карточку товара', 'искал в базе знаний', 'предложил оставить контакты']);
});

it('знает все инструменты бота — ни один не показывается голым именем', function (): void {
    // Инструмент, добавленный в реестр, но забытый здесь, в «Диалогах»
    // выглядел бы строкой из кода. Сверяем с самим реестром, а не со списком.
    $calls = array_map(
        static fn ($tool): array => ['name' => $tool->name(), 'arguments' => [], 'ms' => 0],
        AiSupportServiceProvider::tools(),
    );

    $phrases = ChatMessageTelemetry::for(telemetryAnswer(['tool_calls' => $calls]))->toolPhrases;

    foreach ($calls as $call) {
        expect($phrases)->not->toContain($call['name']);
    }
});

it('считает повторные вызовы одного инструмента', function (): void {
    $message = telemetryAnswer(['tool_calls' => [
        ['name' => 'search_knowledge_base', 'arguments' => ['query' => 'гарантия'], 'ms' => 800],
        ['name' => 'search_knowledge_base', 'arguments' => ['query' => 'сервис'], 'ms' => 700],
    ]]);

    expect(ChatMessageTelemetry::for($message)->toolPhrases)->toBe(['искал в базе знаний (2 раза)']);
});

it('показывает незнакомый инструмент как есть, а не прячет', function (): void {
    // Инструмент, который забыли вписать в перевод, должен бросаться
    // в глаза: иначе он исчезнет из объяснения вместе со своим вкладом.
    $message = telemetryAnswer(['tool_calls' => [['name' => 'check_stock', 'arguments' => [], 'ms' => 5]]]);

    expect(ChatMessageTelemetry::for($message)->toolPhrases)->toBe(['check_stock']);
});

it('сворачивает куски одной страницы в один источник', function (): void {
    // Одна страница нарезана на фрагменты и легко занимает три места
    // в выдаче из пяти; править всё равно пойдут её целиком.
    $message = telemetryAnswer(['citations' => [
        ['chunk_id' => 'intertooler-kb:1#0', 'score' => 0.598, 'title' => 'Как вернуть товар', 'url' => null],
        ['chunk_id' => 'intertooler-page:dostavka-i-oplata#0', 'score' => 0.594, 'title' => 'Доставка и оплата', 'url' => 'https://i.ru/page/dostavka-i-oplata'],
        ['chunk_id' => 'intertooler-page:dostavka-i-oplata#1', 'score' => 0.587, 'title' => 'Доставка и оплата', 'url' => 'https://i.ru/page/dostavka-i-oplata'],
        ['chunk_id' => 'intertooler-page:dostavka-i-oplata#4', 'score' => 0.463, 'title' => 'Доставка и оплата', 'url' => 'https://i.ru/page/dostavka-i-oplata'],
    ]]);

    $sources = ChatMessageTelemetry::for($message)->sources;

    expect($sources)->toHaveCount(2)
        ->and($sources[0]['title'])->toBe('Как вернуть товар')
        ->and($sources[0]['url'])->toBeNull()
        ->and($sources[1]['chunks'])->toBe(3)
        // Оценка документа — лучшая среди его кусков, а не последняя.
        ->and(round($sources[1]['score'], 3))->toBe(0.594);
});

it('различает статьи базы знаний по названию — своего адреса у них нет', function (): void {
    $message = telemetryAnswer(['citations' => [
        ['chunk_id' => 'intertooler-kb:1#0', 'score' => 0.6, 'title' => 'Как вернуть товар', 'url' => null],
        ['chunk_id' => 'intertooler-kb:5#0', 'score' => 0.58, 'title' => 'Условия гарантии', 'url' => null],
    ]]);

    expect(ChatMessageTelemetry::for($message)->sources)->toHaveCount(2);
});

it('не спотыкается на пустой телеметрии', function (): void {
    $telemetry = ChatMessageTelemetry::for(telemetryAnswer());

    expect($telemetry->toolPhrases)->toBe([])->and($telemetry->sources)->toBe([]);
});
