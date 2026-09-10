<?php

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Соединение очереди для ассистента.
 *
 * Тест держит не «настройку», а решение: у ответа бота свой retry_after,
 * потому что задаётся он на соединение, и общий с импортом (1800 на бою)
 * означал бы, что повисший диалог ждёт полчаса вместо пяти минут.
 */

it('gives the assistant its own queue connection', function (): void {
    $connection = Config::get('queue.connections.redis-assistant');

    expect($connection)->not->toBeNull()
        ->and($connection['driver'])->toBe('redis')
        ->and($connection['queue'])->toBe('assistant')
        // Тот же инстанс Redis, что и у остальной очереди: второй заводить
        // незачем, разделение здесь логическое, а не по железу.
        ->and($connection['connection'])->toBe(Config::get('queue.connections.redis.connection'));
});

it('keeps the timeout invariant that prevents a double paid call', function (): void {
    /*
     * Джоба 200 < воркер 240 < retry_after 300.
     *
     * Джоба обязана сдаться сама раньше, чем её снимет воркер, а воркер —
     * раньше, чем очередь сочтёт её потерянной и выдаст второй экземпляр:
     * иначе на один вопрос покупателя уедет два платных вызова шлюза.
     * Первые два числа появятся в джобе и в `composer dev`, третье — здесь.
     */
    expect(Config::get('queue.connections.redis-assistant.retry_after'))->toBe(300);
});
