<?php

namespace App\Filament\Resources\LegacyProducts;

use App\Filament\Resources\LegacyProducts\Pages\ListLegacyProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Models\LegacyProduct;
use App\Models\Product;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LegacyProductResource extends Resource
{
    protected static ?string $model = LegacyProduct::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-right';

    protected static ?string $navigationLabel = 'KratonKuban товары';

    protected static ?string $modelLabel = 'KratonKuban товар';

    protected static ?string $pluralModelLabel = 'KratonKuban товары';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('matchedProduct:id,name,slug,sku'))
            ->columns([
                TextColumn::make('source_path')
                    ->label('KratonKuban URL')
                    ->searchable()
                    ->copyable()
                    ->url(fn (LegacyProduct $record): string => static::legacyUrl($record))
                    ->openUrlInNewTab(),
                TextColumn::make('name')
                    ->label('KratonKuban наименование')
                    ->searchable()
                    ->wrap()
                    ->limit(80),
                TextColumn::make('sku')
                    ->label('KratonKuban артикул')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('manufacturer')
                    ->label('Производитель')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('matchedProduct.name')
                    ->label('Intertooler товар')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('matchedProduct', function (Builder $query) use ($search): void {
                            $query
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('sku', 'like', "%{$search}%")
                                ->orWhere('slug', 'like', "%{$search}%");
                        });
                    })
                    ->url(fn (LegacyProduct $record): ?string => $record->matchedProduct
                        ? ProductResource::getUrl('edit', ['record' => $record->matchedProduct])
                        : null)
                    ->wrap()
                    ->limit(80),
                TextColumn::make('match_strategy')
                    ->label('Тип соответствия')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::matchStrategyLabel($state))
                    ->placeholder('Без соответствия'),
                TextColumn::make('match_source')
                    ->label('Источник')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::matchSourceLabel($state))
                    ->placeholder('Не указан'),
                IconColumn::make('match_locked')
                    ->label('Заблокировано')
                    ->boolean(),
                IconColumn::make('redirect_enabled')
                    ->label('Редирект')
                    ->boolean(),
                TextColumn::make('matched_at')
                    ->label('Дата соответствия')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('match_state')
                    ->label('Состояние')
                    ->options([
                        'matched' => 'Есть соответствие',
                        'unmatched' => 'Без соответствия',
                        'manual' => 'Ручные',
                        'auto' => 'Авто',
                        'removed' => 'Убрано вручную',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'matched' => $query->whereNotNull('matched_product_id'),
                            'unmatched' => $query
                                ->whereNull('matched_product_id')
                                ->where(function (Builder $query): void {
                                    $query
                                        ->whereNull('match_strategy')
                                        ->orWhere('match_strategy', '!=', LegacyProduct::STRATEGY_MANUAL_REMOVED);
                                }),
                            'manual' => $query->where('match_source', LegacyProduct::MATCH_SOURCE_MANUAL),
                            'auto' => $query->where('match_source', LegacyProduct::MATCH_SOURCE_AUTO),
                            'removed' => $query->where('match_strategy', LegacyProduct::STRATEGY_MANUAL_REMOVED),
                            default => $query,
                        };
                    }),
                SelectFilter::make('match_strategy')
                    ->label('Тип соответствия')
                    ->options([
                        'sku_exact' => 'Артикул точно',
                        'sku_normalized' => 'Артикул нормализованный',
                        'name_normalized' => 'Наименование нормализованное',
                        LegacyProduct::STRATEGY_MANUAL => 'Ручное',
                        LegacyProduct::STRATEGY_MANUAL_REMOVED => 'Убрано вручную',
                    ]),
                TernaryFilter::make('redirect_enabled')
                    ->label('Редирект'),
                TernaryFilter::make('match_locked')
                    ->label('Заблокировано'),
            ])
            ->recordActions([
                Action::make('matchManually')
                    ->label('Добавить соответствие')
                    ->icon('heroicon-o-link')
                    ->form([
                        Select::make('product_id')
                            ->label('Intertooler товар')
                            ->searchable()
                            ->required()
                            ->getSearchResultsUsing(
                                fn (string $search): array => Product::query()
                                    ->where(function (Builder $query) use ($search): void {
                                        $query
                                            ->where('name', 'like', "%{$search}%")
                                            ->orWhere('sku', 'like', "%{$search}%")
                                            ->orWhere('slug', 'like', "%{$search}%");
                                    })
                                    ->orderBy('name')
                                    ->limit(50)
                                    ->get(['id', 'name', 'sku'])
                                    ->mapWithKeys(
                                        fn (Product $product): array => [
                                            $product->getKey() => static::productOptionLabel($product),
                                        ]
                                    )
                                    ->all()
                            )
                            ->getOptionLabelUsing(function (mixed $value): ?string {
                                $product = Product::query()->find($value);

                                return $product instanceof Product ? static::productOptionLabel($product) : null;
                            }),
                    ])
                    ->action(function (LegacyProduct $record, array $data): void {
                        /** @var Product $product */
                        $product = Product::query()->findOrFail($data['product_id']);
                        $record->applyManualMatch($product, static::currentUser());

                        Notification::make()
                            ->success()
                            ->title('Соответствие добавлено')
                            ->send();
                    }),
                Action::make('removeMatch')
                    ->label('Убрать соответствие')
                    ->icon('heroicon-o-link-slash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Убрать соответствие с KratonKuban товаром?')
                    ->modalDescription('Соответствие будет убрано вручную и заблокировано от повторного автоматического матчинга.')
                    ->action(function (LegacyProduct $record): void {
                        $record->removeManualMatch(static::currentUser());

                        Notification::make()
                            ->success()
                            ->title('Соответствие убрано')
                            ->send();
                    }),
                Action::make('openLegacy')
                    ->label('')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (LegacyProduct $record): string => static::legacyUrl($record))
                    ->openUrlInNewTab(),
                Action::make('openProduct')
                    ->label('')
                    ->icon('heroicon-o-briefcase')
                    ->visible(fn (LegacyProduct $record): bool => $record->matchedProduct instanceof Product)
                    ->url(fn (LegacyProduct $record): ?string => $record->matchedProduct
                        ? route('product.show', $record->matchedProduct)
                        : null)
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLegacyProducts::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    private static function productOptionLabel(Product $product): string
    {
        return trim("{$product->name} | {$product->sku}");
    }

    private static function legacyUrl(LegacyProduct $record): string
    {
        return 'https://kratonkuban.ru'.$record->source_path;
    }

    private static function matchStrategyLabel(?string $state): string
    {
        return match ($state) {
            'sku_exact' => 'Артикул точно',
            'sku_normalized' => 'Артикул нормализованный',
            'name_normalized' => 'Наименование нормализованное',
            LegacyProduct::STRATEGY_MANUAL => 'Ручное',
            LegacyProduct::STRATEGY_MANUAL_REMOVED => 'Убрано вручную',
            default => (string) $state,
        };
    }

    private static function matchSourceLabel(?string $state): string
    {
        return match ($state) {
            LegacyProduct::MATCH_SOURCE_AUTO => 'Авто',
            LegacyProduct::MATCH_SOURCE_MANUAL => 'Ручное',
            default => (string) $state,
        };
    }

    private static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
