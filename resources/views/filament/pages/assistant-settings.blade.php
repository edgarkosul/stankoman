{{--
    Настройки бота — обычная форма Filament.
--}}
<x-filament-panels::page>
    @if ($this->emergencyOverride())
        {{--
            Текст идёт слотом `description`: обычное содержимое компонент
            Filament выбрасывает молча, и плашка получается пустой.
        --}}
        <x-filament::callout icon="heroicon-o-exclamation-triangle" color="danger">
            <x-slot name="heading">Бот выключен аварийно</x-slot>
            <x-slot name="description">
                Выключение сделано строкой AI_AGENT_ENABLED=false в настройках сервера.
                Пока она стоит, выключатель ниже ни на что не влияет: снять аварийное
                выключение может только разработчик.
            </x-slot>
        </x-filament::callout>
    @endif

    {{ $this->form }}
</x-filament-panels::page>
