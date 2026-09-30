<?php

namespace App\Filament\Resources\ChatConversations\Pages;

use App\Filament\Resources\ChatConversations\ChatConversationResource;
use App\Filament\Widgets\AssistantSpendOverview;
use Filament\Resources\Pages\ListRecords;

class ListChatConversations extends ListRecords
{
    protected static string $resource = ChatConversationResource::class;

    /**
     * Расход на бота — над списком диалогов, а не на общей панели админки.
     *
     * Это место, куда приходят смотреть, как бот работает; деньги относятся
     * туда же. Рядом с заказами и остатками цифра стояла бы среди чужих ей
     * чисел, где на неё не смотрят.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            AssistantSpendOverview::class,
        ];
    }
}
