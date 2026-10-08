{{--
    «Вид чата»: форма слева, предпросмотр справа.

    Предпросмотр — документ в `srcdoc` iframe без скриптов (`sandbox`): стили
    витрины в админке задели бы Filament. Новый документ приходит с каждой
    перерисовкой формы — поля живые, и вид кнопки виден сразу, до сохранения.
--}}
<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_400px]">
        <div class="min-w-0">
            {{ $this->form }}
        </div>

        <div class="self-start lg:sticky lg:top-20">
            <x-filament::section>
                <x-slot name="heading">Предпросмотр</x-slot>
                <x-slot name="description">Угол витрины с кнопкой и подсказкой. Сама подсказка появляется раз за визит, через несколько секунд на странице.</x-slot>

                <iframe sandbox="" title="Предпросмотр кнопки чата" class="block h-[560px] w-full rounded-lg"
                        srcdoc="{{ $this->previewDocument() }}"></iframe>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
