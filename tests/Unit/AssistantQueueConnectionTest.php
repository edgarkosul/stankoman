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
     *
     * Числа лежат в трёх разных файлах, и в этом вся опасность: поправит
     * человек одно, а инвариант держится всеми тремя. Поэтому сверяем их
     * здесь, а не полагаемся на комментарии. Таймаут джобы приедет сюда
     * вместе с самой джобой.
     */
    $retryAfter = Config::get('queue.connections.redis-assistant.retry_after');
    $worker = assistantWorkerTimeout();

    expect($retryAfter)->toBe(300)
        ->and($worker)->toBe(240)
        ->and($worker)->toBeLessThan($retryAfter);
});

/**
 * Таймаут воркера ассистента — из `composer dev`, где он и живёт.
 */
function assistantWorkerTimeout(): int
{
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $dev = implode(' ', (array) ($composer['scripts']['dev'] ?? []));

    expect($dev)->toContain('queue:work redis-assistant --queue=assistant');

    preg_match('/queue:work redis-assistant[^"]*--timeout=(\d+)/', $dev, $m);

    return (int) ($m[1] ?? 0);
}
