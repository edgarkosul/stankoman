{{--
    Страница диалога почти пуста намеренно: вся работа идёт внутри
    Livewire-панели, которая обновляется опросом сама и не таскает
    за собой перерисовку остальной страницы Filament.
--}}
<x-filament-panels::page>
    @livewire('admin.chat-conversation-panel', ['conversationId' => $this->getRecord()->getKey()])
</x-filament-panels::page>
