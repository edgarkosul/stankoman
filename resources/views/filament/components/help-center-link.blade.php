@php($helpUrl = \App\Support\Filament\HelpCenter::urlForCurrentRoute())

@if ($helpUrl)
    <x-filament::button
        tag="a"
        color="gray"
        icon="heroicon-o-question-mark-circle"
        :href="$helpUrl"
        target="_blank"
        rel="noopener noreferrer"
    >
        Инструкция
    </x-filament::button>
@endif
