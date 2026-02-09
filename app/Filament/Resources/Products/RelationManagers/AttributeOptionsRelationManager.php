<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Tables;
use Filament\Actions;
use App\Models\Product;
use App\Models\Attribute;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use App\Models\AttributeOption;
use Filament\Forms\Components\Select;
use App\Models\ProductAttributeOption;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Resources\RelationManagers\RelationManager;

class AttributeOptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'attributeOptions';

    protected static ?string $title = 'Фильтры — заданные варианты';

    private const OPTION_INPUT_TYPES = ['select', 'multiselect'];

    public static function getModelLabel(): string
    {
        return 'заданный вариант';
    }

    public static function getPluralModelLabel(): string
    {
        return 'заданные варианты';
    }

    public function form(Schema $schema): Schema
    {
        // Форма не используется напрямую — поля задаём в экшенах.
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('value')
            ->columns([
                TextColumn::make('attribute.id')
                    ->label('ID')
                    ->badge(),
                TextColumn::make('attribute.name')
                    ->label('Атрибут')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('value')
                    ->label('Вариант')
                    ->searchable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading('Нет заданных вариантов')
            ->emptyStateDescription('Выбор из списка (select/multiselect) редактируется здесь.')
            ->modifyQueryUsing(function (Builder $query) {
                $query->whereHas('attribute', fn(Builder $attrQuery) => $attrQuery->whereIn('input_type', self::OPTION_INPUT_TYPES));
            })
            ->headerActions([
                // Показать кнопку только если есть что добавлять
                Actions\Action::make('attachOption')
                    ->label('Добавить вариант')
                    ->icon('heroicon-o-plus')
                    ->visible(fn() => ! empty($this->getAttachableAttributeOptions()))
                    ->schema(function (): array {
                        return [
                            Select::make('attribute_id')
                                ->label('Атрибут')
                                ->options($this->getAttachableAttributeOptions())
                                ->searchable()
                                ->preload()
                                ->required()
                                ->live()
                                ->hint(fn() => empty($this->getAttachableAttributeOptions()) ? 'Все варианты уже выбраны.' : null),

                            Select::make('attribute_option_id')
                                ->label('Вариант')
                                ->options(function (Get $get) {
                                    $attrId = (int) $get('attribute_id');
                                    return $attrId
                                        ? $this->getAvailableOptionsForAttribute($attrId)
                                        : [];
                                })
                                ->searchable()
                                ->preload()
                                ->required()
                                ->disabled(fn(Get $get) => $get('attribute_id') ? empty($this->getAvailableOptionsForAttribute((int) $get('attribute_id'))) : true)
                                ->hint(fn(Get $get) => $get('attribute_id') && empty($this->getAvailableOptionsForAttribute((int) $get('attribute_id')))
                                    ? 'Для этого атрибута все варианты уже выбраны.'
                                    : null),
                        ];
                    })
                    ->action(function (array $data): void {
                        /** @var Product $product */
                        $product     = $this->getOwnerRecord();
                        $attributeId = (int) ($data['attribute_id'] ?? 0);
                        $optionId    = (int) ($data['attribute_option_id'] ?? 0);
                        if (! $attributeId || ! $optionId) return;

                        $attr = Attribute::find($attributeId);
                        if (! $attr) return;

                        if ($attr->input_type === 'multiselect') {
                            // ➕ добавляем, не трогая уже выбранные (дубли БД не допустит)
                            $product->attributeOptions()
                                ->syncWithoutDetaching([$optionId => ['attribute_id' => $attributeId]]);
                        } else {
                            // select: заменить выбор (если уже был)
                            ProductAttributeOption::setSingle($product->getKey(), $attributeId, $optionId);
                        }
                    })
                    ->successNotificationTitle('Вариант добавлен'),
            ])
            ->recordActions([
                // Изменить конкретный вариант (для multiselect — не предлагаем уже выбранные у этого же атрибута)
                Actions\Action::make('editOption')
                    ->label('Изменить')
                    ->icon('heroicon-o-pencil')
                    ->schema(function (Model $record): array {
                        /** @var AttributeOption $record */
                        $attrId = (int) $record->attribute_id;

                        return [
                            Select::make('attribute_option_id')
                                ->label('Вариант')
                                ->options($this->getAvailableOptionsForAttribute($attrId, allowId: (int) $record->getKey()))
                                ->default($record->getKey())
                                ->searchable()
                                ->preload()
                                ->required(),
                        ];
                    })
                    ->action(function (array $data, Model $record): void {
                        /** @var AttributeOption $record */
                        /** @var Product $product */
                        $product = $this->getOwnerRecord();
                        $oldId   = (int) $record->getKey();
                        $attrId  = (int) $record->attribute_id;
                        $newId   = (int) ($data['attribute_option_id'] ?? 0);

                        if (! $newId || $newId === $oldId) return;

                        // заменить конкретный вариант: убрать старый → добавить новый
                        $product->attributeOptions()->detach($oldId);
                        $product->attributeOptions()
                            ->syncWithoutDetaching([$newId => ['attribute_id' => $attrId]]);
                    })
                    ->successNotificationTitle('Вариант обновлён'),

                Actions\DetachAction::make()
                    ->label('Убрать')
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DetachBulkAction::make()
                        ->label('Убрать выбранные'),
                ]),
            ]);
    }

    /**
     * Список атрибутов, для которых сейчас есть что добавить:
     * - select: если ещё нет выбранного значения,
     * - multiselect: если не все варианты выбраны.
     *
     * Учитываем (по возможности) атрибуты первичной категории.
     */
    private function getAttachableAttributeOptions(): array
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        $attributeQuery = Attribute::query()
            ->whereIn('input_type', self::OPTION_INPUT_TYPES)
            ->orderBy('name');

        if (
            method_exists($product, 'getPrimaryCategoryAttributes')
            && ($attrs = $product->getPrimaryCategoryAttributes()) && $attrs->isNotEmpty()
        ) {
            $attributeQuery->whereIn('id', $attrs->pluck('id'));
        }

        // сколько уже выбрано у продукта по каждому атрибуту
        $selectedCounts = $product->attributeOptions()
            ->selectRaw('product_attribute_option.attribute_id, COUNT(*) as cnt')
            ->groupBy('product_attribute_option.attribute_id')
            ->pluck('cnt', 'product_attribute_option.attribute_id'); // [attrId => cnt]

        // сколько всего вариантов у каждого атрибута
        $totalCounts = AttributeOption::query()
            ->selectRaw('attribute_id, COUNT(*) as cnt')
            ->groupBy('attribute_id')
            ->pluck('cnt', 'attribute_id'); // [attrId => cnt]

        return $attributeQuery->get()
            ->filter(function (Attribute $attr) use ($selectedCounts, $totalCounts) {
                $attrId   = $attr->getKey();
                $selected = (int) ($selectedCounts[$attrId] ?? 0);
                $total    = (int) ($totalCounts[$attrId] ?? 0);

                if ($total === 0) {
                    return false;
                }

                if ($attr->input_type === 'select') {
                    // можно добавить только если ещё не выбрано ничего
                    return $selected === 0;
                }

                // multiselect — если выбрали не все
                return $selected < $total;
            })
            ->mapWithKeys(fn(Attribute $attr) => [$attr->id => $this->attributeLabel($attr)])
            ->all();
    }

    /**
     * Варианты, которые можно выбрать для атрибута прямо сейчас:
     * исключаем уже выбранные значения. Можно разрешить один id (например, текущий при редактировании).
     */
    private function getAvailableOptionsForAttribute(int $attrId, ?int $allowId = null): array
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        $selectedIds = $product->attributeOptions()
            ->where('attribute_options.attribute_id', $attrId)
            ->pluck('attribute_options.id')
            ->all();

        if ($allowId) {
            // при редактировании оставляем текущий вариант в списке
            $selectedIds = array_values(array_diff($selectedIds, [$allowId]));
        }

        return AttributeOption::query()
            ->where('attribute_id', $attrId)
            ->when($selectedIds, fn($q) => $q->whereNotIn('id', $selectedIds))
            ->orderBy('sort_order')
            ->orderBy('value')
            ->pluck('value', 'id')
            ->all();
    }

    private function attributeLabel(Attribute $attr): string
    {
        return "{$attr->name} [ID: {$attr->id}]";
    }
}
