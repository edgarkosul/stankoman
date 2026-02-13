<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('slug'),
                TextEntry::make('sku')
                    ->label('SKU')
                    ->placeholder('-'),
                TextEntry::make('brand')
                    ->placeholder('-'),
                TextEntry::make('country')
                    ->placeholder('-'),
                TextEntry::make('price_amount')
                    ->numeric(),
                TextEntry::make('currency'),
                IconEntry::make('in_stock')
                    ->boolean(),
                TextEntry::make('qty')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('popularity')
                    ->numeric(),
                IconEntry::make('is_active')
                    ->boolean(),
                TextEntry::make('short')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('description')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('extra_description')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('specs')
                    ->formatStateUsing(static function (mixed $state): ?string {
                        if (! is_array($state) || $state === []) {
                            return null;
                        }

                        $lines = [];

                        foreach ($state as $spec) {
                            if (! is_array($spec)) {
                                continue;
                            }

                            $name = trim((string) ($spec['name'] ?? ''));
                            $value = trim((string) ($spec['value'] ?? ''));

                            if ($name === '' || $value === '') {
                                continue;
                            }

                            $lines[] = $name.': '.$value;
                        }

                        if ($lines === []) {
                            return null;
                        }

                        return implode(PHP_EOL, $lines);
                    })
                    ->placeholder('-')
                    ->columnSpanFull(),
                ImageEntry::make('image')
                    ->placeholder('-'),
                TextEntry::make('thumb')
                    ->placeholder('-'),
                TextEntry::make('gallery')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('meta_title')
                    ->placeholder('-'),
                TextEntry::make('meta_description')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('promo_info')
                    ->placeholder('-'),
            ]);
    }
}
