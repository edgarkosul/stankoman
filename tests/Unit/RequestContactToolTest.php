<?php

use App\Livewire\Common\RequestCallback;
use App\Services\Ai\Tools\EscalateToOperatorTool;
use App\Services\Ai\Tools\RequestContactTool;
use App\Services\Ai\Tools\ToolContext;

// Текст возврата инструмента модель читает как факт — так же, как его имя.
// Диалог 17 (13.09.2026): возврат говорил «форма обратного звонка» и
// «перезвонит», и бот трижды обещал звонок при канале «письмо».

it('говорит модели о письме, а не о звонке', function (): void {
    $result = (new RequestContactTool)->run(['topic' => 'Счёт на генератор'], new ToolContext);

    expect($result)->toContain('письмом')
        ->and($result)->toContain('почту')
        ->and($result)->not->toContain('перезвон')
        ->and($result)->not->toContain('обратного звонка');
});

it('называет модели кнопку, за которой прячется форма', function (): void {
    // Приёмка 13.09.2026: бот писал «впишите почту в форму под перепиской»,
    // а на экране была только кнопка, открывающая окно с полями.
    $result = (new RequestContactTool)->run(['topic' => 'Счёт'], new ToolContext);

    expect($result)->toContain('«'.RequestCallback::CONTACT_BUTTON.'»');
});

it('поднимает флаг формы и подрезает тему', function (): void {
    $context = new ToolContext;

    (new RequestContactTool)->run(['topic' => str_repeat('я', 400)], $context);

    expect($context->callbackRequested)->toBeTrue()
        ->and(mb_strlen((string) $context->callbackTopic))->toBe(300);
});

it('при передаче вопроса менеджеру тоже не обещает звонок', function (): void {
    $result = (new EscalateToOperatorTool)->run(['reason' => 'нужен счёт'], new ToolContext);

    expect($result)->toContain('письмом')
        ->and($result)->not->toContain('перезвон');
});

it('предупреждает модель, что кнопка появляется только после вызова', function (): void {
    // Приёмка 14.09.2026: бот называл кнопку, не вызвав форму, и покупатель
    // искал на экране то, чего там не было.
    $description = (new RequestContactTool)->definition()['function']['description'];

    expect($description)->toContain('«'.RequestCallback::CONTACT_BUTTON.'» появляется у покупателя только после');
});
