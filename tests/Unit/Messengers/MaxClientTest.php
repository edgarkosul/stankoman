<?php

use App\Services\Messengers\ChatGone;
use App\Services\Messengers\MaxClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * Клиент проверяется без базы: он знает только HTTP. Приложение
 * поднимается ради фасада Http.
 */
uses(TestCase::class);

function maxClient(?string $token = 'max-token', ?string $link = 'https://max.ru/id1_bot'): MaxClient
{
    return new MaxClient(token: $token, botLink: $link, baseUrl: 'https://platform-api.max.ru', timeout: 8);
}

it('шлёт токен заголовком, а не параметром access_token', function (): void {
    Http::fake(['platform-api.max.ru/*' => Http::response(['message' => ['body' => []]])]);

    maxClient()->send('777', 'Новый заказ');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://platform-api.max.ru/messages?chat_id=777'
        && $request->header('Authorization') === ['max-token']
        && ! str_contains($request->url(), 'access_token')
        && $request['text'] === 'Новый заказ');
});

it('обрезает текст до предела MAX', function (): void {
    Http::fake(['platform-api.max.ru/*' => Http::response([])]);

    maxClient()->send('777', str_repeat('я', MaxClient::MAX_TEXT + 50));

    Http::assertSent(fn (Request $request): bool => mb_strlen($request['text']) === MaxClient::MAX_TEXT);
});

it('считает 403 и 404 ушедшим чатом, а прочее — временным сбоем', function (int $status, string $exception): void {
    Http::fake(['platform-api.max.ru/*' => Http::response(['message' => 'chat.denied'], $status)]);

    expect(fn () => maxClient()->send('777', 'x'))->toThrow($exception, 'chat.denied');
})->with([
    'бот остановлен' => [403, ChatGone::class],
    'чата нет' => [404, ChatGone::class],
    'сбой MAX' => [500, RuntimeException::class],
]);

it('не ходит в MAX без токена или ссылки на бота', function (): void {
    Http::fake();

    expect(maxClient(link: null)->configured())->toBeFalse()
        ->and(fn () => maxClient(token: null)->send('777', 'x'))->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

it('подписывает webhook на подключение и отключение чата', function (): void {
    Http::fake(['platform-api.max.ru/*' => Http::response(['success' => true])]);

    maxClient()->subscribe('https://intertooler.ru/hooks/max', 's3cret');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://platform-api.max.ru/subscriptions'
        && $request['secret'] === 's3cret'
        && $request['update_types'] === ['bot_started', 'bot_stopped', 'bot_removed']);
});

it('строит ссылку подключения с кодом', function (): void {
    expect(maxClient(link: 'https://max.ru/id1_bot/')->startUrl('abc'))->toBe('https://max.ru/id1_bot?start=abc');
});
