<?php

namespace App\Filament\Resources\CallbackRequests\Tables;

use App\Models\CallbackRequest;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CallbackRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Создана')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Имя')
                    ->limit(40)
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('phone')
                    ->label('Телефон')
                    ->placeholder('—')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('email')
                    ->label('Почта')
                    ->placeholder('—')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('product.name')
                    ->label('Товар')
                    ->limit(30)
                    ->placeholder('—')
                    ->url(fn (CallbackRequest $record): ?string => $record->product
                        ? route('product.show', $record->product)
                        : null, shouldOpenInNewTab: true)
                    ->toggleable(),

                TextColumn::make('comments')
                    ->label('Комментарий')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('source')
                    ->label('Откуда')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CallbackRequest::sourceLabels()[$state] ?? $state)
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CallbackRequest::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->sortable(),

                // Пусто — значит письмо менеджеру не ушло; причина на странице заявки.
                TextColumn::make('notified_at')
                    ->label('Письмо ушло')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('не отправлено')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(CallbackRequest::statusLabels())
                    ->default(CallbackRequest::STATUS_PENDING),

                SelectFilter::make('source')
                    ->label('Откуда')
                    ->options(CallbackRequest::sourceLabels()),
            ])
            ->recordActions([
                ViewAction::make(),
                self::markContactedAction(),
                self::cancelAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function markContactedAction(): Action
    {
        return Action::make('markContacted')
            ->label('Связались')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->visible(fn (CallbackRequest $record): bool => $record->status !== CallbackRequest::STATUS_CALLED)
            ->action(fn (CallbackRequest $record) => $record->update(['status' => CallbackRequest::STATUS_CALLED]));
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Отменить')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('gray')
            ->visible(fn (CallbackRequest $record): bool => $record->status === CallbackRequest::STATUS_PENDING)
            ->action(fn (CallbackRequest $record) => $record->update(['status' => CallbackRequest::STATUS_CANCELLED]));
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            CallbackRequest::STATUS_PENDING => 'warning',
            CallbackRequest::STATUS_CALLED => 'success',
            default => 'gray',
        };
    }
}
