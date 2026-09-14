<?php

namespace App\Filament\Resources\CallbackRequests\Pages;

use App\Filament\Resources\CallbackRequests\CallbackRequestResource;
use App\Filament\Resources\CallbackRequests\Tables\CallbackRequestsTable;
use Filament\Resources\Pages\ViewRecord;

class ViewCallbackRequest extends ViewRecord
{
    protected static string $resource = CallbackRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CallbackRequestsTable::markContactedAction(),
            CallbackRequestsTable::cancelAction(),
        ];
    }
}
