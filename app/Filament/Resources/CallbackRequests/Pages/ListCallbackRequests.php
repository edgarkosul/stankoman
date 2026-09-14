<?php

namespace App\Filament\Resources\CallbackRequests\Pages;

use App\Filament\Resources\CallbackRequests\CallbackRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListCallbackRequests extends ListRecords
{
    protected static string $resource = CallbackRequestResource::class;
}
