<?php

namespace App\Filament\Resources\LegacyProducts\Pages;

use App\Filament\Resources\LegacyProducts\LegacyProductResource;
use Filament\Resources\Pages\ListRecords;

class ListLegacyProducts extends ListRecords
{
    protected static string $resource = LegacyProductResource::class;
}
