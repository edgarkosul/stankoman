<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\LegacyProduct;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LegacyProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'legacyProducts';

    protected static ?string $title = 'Legacy Kraton';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('source_path')
                    ->label('Legacy URL')
                    ->searchable()
                    ->copyable()
                    ->url(fn (LegacyProduct $record): string => 'https://kratonkuban.ru'.$record->source_path)
                    ->openUrlInNewTab(),
                TextColumn::make('name')
                    ->label('Legacy наименование')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('sku')
                    ->label('Артикул')
                    ->searchable(),
                TextColumn::make('manufacturer')
                    ->label('Производитель')
                    ->searchable(),
                TextColumn::make('match_strategy')
                    ->label('Стратегия')
                    ->badge(),
                TextColumn::make('match_source')
                    ->label('Источник')
                    ->badge(),
                IconColumn::make('match_locked')
                    ->label('Locked')
                    ->boolean(),
                IconColumn::make('redirect_enabled')
                    ->label('Redirect')
                    ->boolean(),
            ])
            ->headerActions([
                Action::make('addLegacyMatch')
                    ->label('Добавить legacy-матч')
                    ->icon('heroicon-o-link')
                    ->form([
                        Select::make('legacy_product_id')
                            ->label('Legacy товар')
                            ->searchable()
                            ->required()
                            ->getSearchResultsUsing(
                                fn (string $search): array => $this->legacyProductSearchQuery($search)
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(
                                        fn (LegacyProduct $legacyProduct): array => [
                                            $legacyProduct->getKey() => $this->legacyProductOptionLabel($legacyProduct),
                                        ]
                                    )
                                    ->all()
                            )
                            ->getOptionLabelUsing(function (mixed $value): ?string {
                                $legacyProduct = LegacyProduct::query()->find($value);

                                return $legacyProduct instanceof LegacyProduct
                                    ? $this->legacyProductOptionLabel($legacyProduct)
                                    : null;
                            }),
                    ])
                    ->action(function (array $data): void {
                        /** @var Product $product */
                        $product = $this->getOwnerRecord();

                        /** @var LegacyProduct $legacyProduct */
                        $legacyProduct = LegacyProduct::query()->findOrFail($data['legacy_product_id']);
                        $legacyProduct->applyManualMatch($product, $this->currentUser());

                        Notification::make()
                            ->success()
                            ->title('Legacy-матч добавлен')
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('removeLegacyMatch')
                    ->label('Снять матч')
                    ->icon('heroicon-o-link-slash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Снять legacy-матч?')
                    ->modalDescription('Матч будет снят вручную и заблокирован от повторного автоматического матчинга.')
                    ->action(function (LegacyProduct $record): void {
                        $record->removeManualMatch($this->currentUser());

                        Notification::make()
                            ->success()
                            ->title('Legacy-матч снят')
                            ->send();
                    }),
            ]);
    }

    private function legacyProductSearchQuery(string $search): Builder
    {
        $search = trim($search);

        return LegacyProduct::query()
            ->with('matchedProduct:id,name,slug')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('manufacturer', 'like', "%{$search}%")
                        ->orWhere('source_path', 'like', "%{$search}%");
                });
            })
            ->orderByRaw('matched_product_id is not null')
            ->orderBy('name');
    }

    private function legacyProductOptionLabel(LegacyProduct $legacyProduct): string
    {
        $matchedProduct = $legacyProduct->matchedProduct;
        $matchedLabel = $matchedProduct instanceof Product
            ? " -> {$matchedProduct->name}"
            : '';

        return trim("{$legacyProduct->source_path} | {$legacyProduct->name} | {$legacyProduct->sku}{$matchedLabel}");
    }

    private function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
