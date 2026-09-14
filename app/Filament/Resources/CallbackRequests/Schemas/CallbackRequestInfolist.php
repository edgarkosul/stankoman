<?php

namespace App\Filament\Resources\CallbackRequests\Schemas;

use App\Filament\Resources\CallbackRequests\Tables\CallbackRequestsTable;
use App\Filament\Resources\Users\UserResource;
use App\Models\CallbackRequest;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CallbackRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Покупатель')
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label('Имя')->placeholder('—'),
                    TextEntry::make('user.email')
                        ->label('Учётная запись')
                        ->placeholder('гость')
                        ->url(fn (CallbackRequest $record): ?string => $record->user_id
                            ? UserResource::getUrl('edit', ['record' => $record->user_id])
                            : null),
                    TextEntry::make('phone')
                        ->label('Телефон')
                        ->placeholder('—')
                        ->copyable()
                        ->url(fn (CallbackRequest $record): ?string => filled($record->phone) ? 'tel:'.$record->phone : null),
                    TextEntry::make('email')
                        ->label('Почта')
                        ->placeholder('—')
                        ->copyable()
                        ->url(fn (CallbackRequest $record): ?string => filled($record->email) ? 'mailto:'.$record->email : null),
                    TextEntry::make('city')->label('Город')->placeholder('—'),
                    TextEntry::make('call_time')->label('Удобное время')->placeholder('—'),
                    TextEntry::make('product.name')
                        ->label('Товар')
                        ->placeholder('—')
                        ->url(fn (CallbackRequest $record): ?string => $record->product
                            ? route('product.show', $record->product)
                            : null, shouldOpenInNewTab: true)
                        ->columnSpanFull(),
                    TextEntry::make('comments')->label('Комментарий')->placeholder('—')->columnSpanFull(),
                ]),

            Section::make('Обработка')
                ->columns(2)
                ->schema([
                    TextEntry::make('status')
                        ->label('Статус')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => CallbackRequest::statusLabels()[$state] ?? $state)
                        ->color(fn (string $state): string => CallbackRequestsTable::statusColor($state)),
                    TextEntry::make('source')
                        ->label('Откуда')
                        ->formatStateUsing(fn (string $state): string => CallbackRequest::sourceLabels()[$state] ?? $state),
                    TextEntry::make('created_at')->label('Создана')->dateTime('d.m.Y H:i'),
                    TextEntry::make('notified_at')->label('Письмо менеджерам ушло')->dateTime('d.m.Y H:i')->placeholder('не отправлено'),
                    TextEntry::make('attempts')->label('Попыток отправки'),
                    TextEntry::make('last_error')->label('Ошибка отправки')->placeholder('—'),
                ]),

            Section::make('Технические данные')
                ->collapsed()
                ->columns(2)
                ->schema([
                    TextEntry::make('ip_address')->label('IP')->placeholder('—'),
                    TextEntry::make('user_agent')->label('User-Agent')->placeholder('—'),
                ]),
        ]);
    }
}
