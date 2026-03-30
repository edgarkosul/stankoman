<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\Feeds\YandexMarketFeedGenerator;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateMarketFeedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public bool $failOnTimeout = true;

    public int $tries = 2;

    public function __construct(public ?int $userId = null)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('site_export_market_feed'))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(YandexMarketFeedGenerator $generator): void
    {
        $result = $generator->generate();
        $recipient = $this->recipient();

        if (! $recipient) {
            return;
        }

        $recipient->notifyNow(
            Notification::make()
                ->success()
                ->title('Генерация market.xml завершена')
                ->body(sprintf(
                    'Категорий в фиде: %d. Товарных предложений: %d.',
                    $result['categories'],
                    $result['offers'],
                ))
                ->actions([
                    Action::make('open_market_feed')
                        ->label('Открыть market.xml')
                        ->markAsRead()
                        ->url($this->absoluteUrl('/market.xml')),
                ])
                ->toDatabase(),
        );
    }

    public function failed(?Throwable $exception): void
    {
        $recipient = $this->recipient();

        if (! $recipient) {
            return;
        }

        $recipient->notifyNow(
            Notification::make()
                ->danger()
                ->title('Генерация market.xml завершилась ошибкой')
                ->body($exception?->getMessage() ?: 'Задача завершилась с ошибкой без текста исключения.')
                ->toDatabase(),
        );
    }

    private function recipient(): ?User
    {
        if (! is_int($this->userId)) {
            return null;
        }

        return User::query()->find($this->userId);
    }

    private function absoluteUrl(string $path): string
    {
        $baseUrl = rtrim((string) config('company.site_url', config('app.url')), '/');
        $normalizedPath = '/'.ltrim($path, '/');

        return $baseUrl.$normalizedPath;
    }
}
