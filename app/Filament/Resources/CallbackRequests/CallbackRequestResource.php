<?php

namespace App\Filament\Resources\CallbackRequests;

use App\Filament\Resources\CallbackRequests\Pages\ListCallbackRequests;
use App\Filament\Resources\CallbackRequests\Pages\ViewCallbackRequest;
use App\Filament\Resources\CallbackRequests\Schemas\CallbackRequestInfolist;
use App\Filament\Resources\CallbackRequests\Tables\CallbackRequestsTable;
use App\Models\CallbackRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CallbackRequestResource extends Resource
{
    protected static ?string $model = CallbackRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoneArrowDownLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Заявки на звонок';

    protected static ?string $modelLabel = 'заявка на звонок';

    protected static ?string $pluralModelLabel = 'Заявки на звонок';

    public static function infolist(Schema $schema): Schema
    {
        return CallbackRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CallbackRequestsTable::configure($table);
    }

    // Заявку оставляет покупатель, а не менеджер: создавать и править её в админке нечего.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCallbackRequests::route('/'),
            'view' => ViewCallbackRequest::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = CallbackRequest::query()->pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
