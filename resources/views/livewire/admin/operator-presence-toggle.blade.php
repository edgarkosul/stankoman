{{--
    Пульт режима чата в топбаре: звать ли менеджера в чат и что из этого
    следует для покупателя. Подпись считается по двум состояниям
    сразу — «работает ли бот» и «есть ли менеджер», — потому что по
    одному присутствию она врёт: над выключенным ботом писала бы
    «отвечает бот».

    Тёмных вариантов утилит здесь нет и быть не может: тема админки
    светлая, сторож — tests/Unit/LightThemeOnlyTest.php. Донорский шаблон
    нёс их десятком — все вырезаны.
--}}
<div class="hidden sm:block">
    {{--
        `teleport` здесь обязателен, и это не про перенос узла: в плагине
        Filament этот модификатор означает `strategy: fixed`, то есть
        позиционирование от окна, а не от ближайшего позиционированного
        предка. Предок тут — `.fi-topbar-ctn` со `position: sticky`, и без
        фиксированной стратегии панель уезжала бы вверх за край окна.

        `shift` и `size` — страховка от низкого окна: панель прижимается
        к видимой области и получает max-height по остатку места.
    --}}
    <x-filament::dropdown placement="bottom-end" width="xs" teleport shift size>
        <x-slot name="trigger">
            <button type="button"
                class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-medium text-gray-600 transition hover:bg-gray-100">
                {{--
                    Зелёный — покупатель получит ответ в чате; жёлтый —
                    ответа в чате не будет, но обращение примем.
                --}}
                <span @class([
                    'h-2.5 w-2.5 rounded-full',
                    'bg-success-500' => $tone === 'live',
                    'bg-warning-500' => $tone === 'contacts',
                ])></span>
                <span>{{ $label }}</span>
            </button>
        </x-slot>

        <div class="p-3 text-sm">
            <p class="text-gray-600">{{ $explanation }}</p>

            <p class="mt-2 text-xs text-gray-500">{{ $chatEffect }}</p>

            @if ($botOffReason)
                {{--
                    Выключенный бот сильнее всего, что есть на этом экране:
                    не сказать о нём — значит оставить админа щёлкать
                    режимом без единого следствия.
                --}}
                <p class="mt-2 rounded-md bg-warning-50 px-2 py-1.5 text-xs text-warning-700">
                    {{ $botOffReason }}
                    @if ($settingsUrl)
                        <a href="{{ $settingsUrl }}" class="font-medium underline">Открыть настройки</a>
                    @endif
                </p>
            @endif

            <p class="mt-2 text-xs text-gray-500">
                Разговор, в который вы ответили, остаётся за вами {{ $hold }} минут после
                вашей последней реплики, потом на новые вопросы снова отвечает бот.
            </p>
        </div>

        {{--
            Три режима показываются ВСЕГДА, и текущий отмечен: радиокнопка
            из трёх строк объясняет устройство переключателя сама.
        --}}
        <x-filament::dropdown.header color="gray">Звать менеджера в чат</x-filament::dropdown.header>

        <x-filament::dropdown.list>
            @foreach ($modes as $value => $title)
                <x-filament::dropdown.list.item
                    wire:click="setMode('{{ $value }}')"
                    :icon="$mode === $value ? 'heroicon-m-check-circle' : 'heroicon-o-minus-circle'"
                    :color="$mode === $value ? 'primary' : 'gray'"
                >
                    {{ $title }}{{ $mode === $value ? ' — сейчас' : '' }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</div>
