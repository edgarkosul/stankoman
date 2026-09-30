<?php

namespace App\Filament\Resources\ChatConversations\Tables;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Chat\ChatEscalationService;
use App\Services\Chat\ChatMarkdown;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ChatConversationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_message_at', 'desc')
            // Последняя реплика показывается в колонке; без жадной загрузки
            // это был бы отдельный запрос на каждую строку.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'operator', 'latestMessage']))
            ->emptyStateHeading('Диалогов пока нет')
            ->emptyStateDescription('Здесь появится каждый разговор из чата на витрине — и с ботом, и с менеджером.')
            ->columns([
                TextColumn::make('last_message_at')
                    ->label('Последнее сообщение')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->description(fn (ChatConversation $record): ?string => $record->last_message_at?->diffForHumans()),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->state(fn (ChatConversation $record): string => $record->statusLabel())
                    ->color(fn (ChatConversation $record): string => match (true) {
                        $record->isClosed() => 'gray',
                        $record->isOperatorLed() => 'info',
                        $record->isEscalated() => 'warning',
                        default => 'success',
                    }),

                TextColumn::make('user.name')
                    ->label('Покупатель')
                    ->placeholder('аноним')
                    ->description(fn (ChatConversation $record): ?string => $record->user?->email)
                    ->searchable(),

                /*
                 * О чём говорят. Без этой колонки список диалогов — это
                 * двадцать пять одинаковых строк, и выбрать из них нужную
                 * можно только открывая каждую.
                 */
                TextColumn::make('latestMessage.body')
                    ->label('Последняя реплика')
                    ->wrap()
                    ->limit(90)
                    /*
                     * Разметку снимаем: в ленте она рисуется, а здесь от неё
                     * остались бы звёздочки и скобки со ссылками — ровно
                     * поперёк того, зачем колонка нужна.
                     */
                    ->formatStateUsing(fn (?string $state): string => Str::of(
                        app(ChatMarkdown::class)->toPlainText((string) $state)
                    )
                        ->replaceMatches('/\s+/u', ' ')
                        ->trim()
                        ->value()),

                TextColumn::make('operator.name')
                    ->label('Оператор')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('messages_count')
                    ->label('Сообщений')
                    ->alignRight()
                    ->toggleable(),

                TextColumn::make('cost_rub')
                    ->label('Стоимость')
                    ->alignRight()
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2, ',', ' ').' ₽')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Начат')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                /*
                 * Фильтр есть, но по умолчанию НЕ включён.
                 *
                 * У донора включённым он стоял, и это оказалось хуже. Экран
                 * открывают не только по бейджу: чаще заходят посмотреть, что
                 * вообще происходит в чате. А список, приехавший уже
                 * отфильтрованным, читается как «других диалогов нет».
                 */
                Filter::make('awaiting')
                    ->label('Ждут менеджера')
                    ->query(fn (Builder $query): Builder => $query->awaitingStaff()),

                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        ChatConversation::STATUS_BOT => 'Ведёт бот',
                        ChatConversation::STATUS_OPERATOR => 'У оператора',
                        ChatConversation::STATUS_CLOSED => 'Закрыт',
                    ]),

                Filter::make('escalated')
                    ->label('Передавались менеджеру')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('escalated_at')),

                /*
                 * Три входа на экран «почему бот плохо ответил», и они
                 * НЕ взаимозаменяемы.
                 *
                 * «Оценено плохо» — прямой сигнал от покупателя, самый
                 * дорогой и самый редкий. «Ничего не нашёл» — сигнал слабый:
                 * по нему не отличить дыру в базе от вопроса не по адресу.
                 * «Бот не справился» — вовсе не про качество ответа, а про то,
                 * что ответа не было: упал шлюз, кончились шаги, не поднялся
                 * воркер.
                 *
                 * Свести их в один фильтр «проблемные» значило бы смешать
                 * работу для базы знаний с работой для разработчика.
                 */
                Filter::make('rated_down')
                    ->label('Оценено плохо')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'messages',
                        fn (Builder $messages): Builder => $messages->where('rating', '<', 0),
                    )),

                Filter::make('kb_miss')
                    ->label('Ничего не нашёл в базе')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'messages',
                        fn (Builder $messages): Builder => $messages->where('kb_miss', true),
                    )),

                Filter::make('failed')
                    ->label('Бот не справился')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'messages',
                        fn (Builder $messages): Builder => $messages
                            ->whereIn('stop_reason', ChatMessage::FAILED_STOP_REASONS),
                    )),
            ])
            ->recordActions([
                ViewAction::make()->label('Открыть'),

                Action::make('takeOver')
                    ->label('Взять в работу')
                    ->icon('heroicon-o-hand-raised')
                    ->color('primary')
                    ->visible(fn (ChatConversation $record): bool => ! $record->isClosed() && ! $record->isOperatorLed())
                    ->action(function (ChatConversation $record, ChatEscalationService $escalation): void {
                        $escalation->takeOver($record, (int) Auth::id(), (string) Auth::user()?->name);

                        Notification::make()
                            ->title('Диалог у вас. Бот в него больше не отвечает.')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
