<?php

use App\Console\Commands\AiKbDoctor;
use Illuminate\Console\OutputStyle;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/*
 * Ключ DeepSeek в докторе: баланс бесплатным запросом. Проверяется и до
 * переключения канала — как только ключ задан. Доктор целиком ходит в базу
 * и очередь, поэтому здесь — одна его секция на настоящей команде.
 */
uses(TestCase::class);

function deepseekDoctor(callable $fake): string
{
    config([
        'ai_support.deepseek.key' => 'deepseek-key',
        'ai_support.deepseek.base_url' => 'https://deepseek.test',
        'ai_support.deepseek.proxy' => '',
        'ai_support.deepseek.usd_rub' => 95,
    ]);

    Http::fake($fake);

    $output = new BufferedOutput;
    $doctor = app(AiKbDoctor::class);
    $doctor->setLaravel(app());
    $doctor->setOutput(new OutputStyle(new ArrayInput([]), $output));

    (fn () => $this->deepseekKey())->call($doctor);

    return $output->fetch();
}

it('ключ DeepSeek: принят, баланс и курс', function (): void {
    $url = null;

    $out = deepseekDoctor(function (Request $request) use (&$url) {
        $url = $request->url();

        return Http::response(['is_available' => true, 'balance_infos' => [['currency' => 'USD', 'total_balance' => '1.75']]]);
    });

    expect($url)->toBe('https://deepseek.test/user/balance')
        ->and($out)->toContain('Ключ принят, баланс 1.75 USD')
        ->and($out)->toContain('95.00 ₽ за $')
        ->and($out)->not->toContain('Баланс меньше $1');
});

it('ключ DeepSeek: баланс меньше доллара — предупреждение', function (): void {
    $out = deepseekDoctor(fn () => Http::response(['is_available' => true, 'balance_infos' => [['currency' => 'USD', 'total_balance' => '0.40']]]));

    expect($out)->toContain('Баланс меньше $1');
});

it('ключ DeepSeek: отказ — крестик с кодом', function (): void {
    $out = deepseekDoctor(fn () => Http::response(['error' => ['message' => 'Authentication Fails']], 401));

    expect($out)->toContain('DeepSeek ответил HTTP 401');
});
