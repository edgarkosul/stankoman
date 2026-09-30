<?php

namespace App\Filament\Pages;

use App\Models\MessengerChannel;
use App\Services\Messengers\ChatGone;
use App\Services\Messengers\MaxClient;
use App\Services\Messengers\MaxLinks;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Throwable;
use UnitEnum;

/**
 * «Уведомления в MAX»: какие чаты менеджеров получают заказы, заявки и вопросы из чата.
 *
 * Чат подключается кнопкой. Она открывает бота магазина с одноразовым
 * кодом, человек жмёт «Запустить», и чат появляется здесь сам: пока код
 * жив, страница раз в пять секунд проверяет, не появился ли он. Номер
 * чата никто не вписывает, а без кода бот никого не подключает.
 */
class MessengerNotifications extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Продажи';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Уведомления в MAX';

    protected static ?string $title = 'Уведомления в MAX';

    protected static ?string $slug = 'messenger-notifications';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.messenger-notifications';

    /** До какого момента ждать нового чата после «Подключить» (unix). */
    public int $awaitingUntil = 0;

    /** Сколько чатов было на момент нажатия: больше стало — дождались. */
    public int $awaitingFrom = 0;

    /** @return Collection<int, MessengerChannel> */
    #[Computed]
    public function channels(): Collection
    {
        return MessengerChannel::query()->orderBy('id')->get();
    }

    public function botReady(): bool
    {
        return app(MaxClient::class)->configured();
    }

    public function isAwaiting(): bool
    {
        return $this->awaitingUntil > now()->getTimestamp();
    }

    /** Тик опроса, пока ждём чат. */
    public function checkConnected(): void
    {
        unset($this->channels);

        if ($this->channels->count() > $this->awaitingFrom) {
            $this->awaitingUntil = 0;
            Notification::make()->success()->title('Чат подключён')->send();

            return;
        }

        if (! $this->isAwaiting()) {
            $this->awaitingUntil = 0;
        }
    }

    public function toggle(int $channelId, string $topic): void
    {
        $flag = MessengerChannel::TOPICS[$topic] ?? null;

        if ($flag === null) {
            return;
        }

        $channel = MessengerChannel::query()->findOrFail($channelId);
        $channel->forceFill([$flag => ! $channel->{$flag}])->save();
        unset($this->channels);
    }

    public function connectAction(): Action
    {
        return Action::make('connect')
            ->label('Подключить MAX')
            ->icon(Heroicon::OutlinedLink)
            ->disabled(fn (): bool => ! $this->botReady())
            ->action(function (): void {
                $url = app(MaxClient::class)->startUrl(app(MaxLinks::class)->issue());

                $this->awaitingFrom = $this->channels->count();
                $this->awaitingUntil = now()->addMinutes(MaxLinks::TTL_MINUTES)->getTimestamp();
                $this->js('window.open('.json_encode($url).', "_blank", "noopener")');

                Notification::make()
                    ->info()
                    ->title('Нажмите «Запустить» в открывшемся чате')
                    ->body(new HtmlString('Чат появится на этой странице сам. Окно не открылось? <a href="'.e($url).'" target="_blank" rel="noopener" class="underline">Откройте ссылку</a>: она действует полчаса и один раз.'))
                    ->persistent()
                    ->send();
            });
    }

    public function testAction(): Action
    {
        return Action::make('test')
            ->label('Проверить')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->link()
            ->action(function (array $arguments): void {
                $channel = MessengerChannel::query()->findOrFail((int) $arguments['channel']);

                try {
                    app(MaxClient::class)->send($channel->chat_id, 'Проверка: уведомления с сайта '.parse_url((string) config('app.url'), PHP_URL_HOST).' приходят сюда.');

                    $channel->forceFill(['enabled' => true, 'last_error' => null, 'failed_at' => null, 'last_sent_at' => now()])->save();
                    Notification::make()->success()->title('Дошло')->body($channel->label)->send();
                } catch (Throwable $e) {
                    $e instanceof ChatGone
                        ? $channel->disable($e->getMessage())
                        : $channel->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 255), 'failed_at' => now()])->save();

                    Notification::make()->danger()->title('Не дошло')->body($e->getMessage())->send();
                }

                unset($this->channels);
            });
    }

    public function removeAction(): Action
    {
        return Action::make('remove')
            ->label('Отключить')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading('Отключить чат?')
            ->modalDescription('Уведомления сюда больше приходить не будут. Подключить снова можно в любой момент.')
            ->action(function (array $arguments): void {
                MessengerChannel::query()->whereKey((int) $arguments['channel'])->delete();
                unset($this->channels);
            });
    }
}
