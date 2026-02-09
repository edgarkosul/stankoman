<?php

namespace App\Filament\Resources\Attributes\RelationManagers;

use App\Models\Category;
use Filament\Tables\Table;
use Filament\Actions\Action;

use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Schema as DBSchema;
use Filament\Actions\EditAction;
use Filament\Actions\AttachAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DetachAction;
use Filament\Actions\AssociateAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\Categories\CategoryResource;
use Filament\Resources\RelationManagers\RelationManager;

class CategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'categories';
    protected static ?string $title = 'Категории (использование)';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->searchable(),
                TextColumn::make('name')->label('Категория')->searchable()->url(fn($record) => CategoryResource::getUrl('edit', ['record' => $record])),
                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->paginated(false)
            ->headerActions([
                Action::make('attachCategory')
                    ->label('Привязать категорию')
                    ->icon('heroicon-m-plus')
                    ->modalHeading('Привязать категорию к атрибуту')
                    ->modalSubmitActionLabel('Привязать')
                    ->schema([
                        Select::make('recordId')
                            ->label('Категория')
                            ->searchable()
                            ->preload()
                            ->options(function () {
                                $attributeId = $this->getOwnerRecord()->getKey();
                                return Category::query()
                                    ->when(
                                        DBSchema::hasColumn('categories', 'is_leaf'),
                                        fn(Builder $q) => $q->where('is_leaf', true)
                                    )
                                    ->whereDoesntHave('attributeDefs', fn(Builder $q) => $q->whereKey($attributeId))
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $this->getRelationship()->attach($data['recordId']);
                        $this->dispatch('attribute-updated');

                    })
                    ->successNotificationTitle('Категория привязана'),
            ])
            ->recordActions([
                Action::make('detachCategory')
                    ->label('Отвязать')
                    ->icon('heroicon-m-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Отвязать эту категорию от атрибута?')
                    ->action(function (\App\Models\Category $record): void {
                        $this->getRelationship()->detach($record->getKey());
                        $this->dispatch('attribute-updated');

                    })
                    ->successNotificationTitle('Категория отвязана'),
            ]); // read-only
    }
}
