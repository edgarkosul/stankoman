<header class="">
    <div class="bg-zinc-100 ">
        <div class="flex max-w-7xl m-auto py-2 px-4 gap-2  justify-between">
            <div class="flex  flex-col gap-8">
                <div class="flex items-center gap-2 text-sm">
                    <x-tooltip {{-- title="Режим работы:" --}} subtitle="г. Краснодар, трасса М4-ДОН">
                        <x-slot:trigger>
                            <x-icon name="spot" class="w-5 h-5" />
                        </x-slot:trigger>
                        пос. Новознаменский, ул. Андреевская, 2
                    </x-tooltip>
                    <span class="hidden md:block">Краснодар</span>
                </div>
            </div>
            <div class="flex gap-3">
                <div class="flex flex-col gap-8">
                    <div class="flex items-center gap-3 text-sm">
                        <a href="https://max.ru/" target="_blank">
                            <x-icon name="max" class="w-5 h-5" />
                        </a>
                        <a href="tg://resolve?phone=79002468660">
                            <x-icon name="telegram" class="w-5 h-5" />
                        </a>
                        <a href="tel:+79002468660" class="flex gap-2">
                            <x-icon name="phone" class="w-5 h-5" />
                            <span class="whitespace-nowrap hidden xs:block">+7 (900) 246-86-60</span>
                        </a>
                    </div>
                </div>
                <div class="border-r border border-zinc-300"></div>
                <div x-data x-tooltip.smart.bottom.offset-10.lt-md="'sale@kratonkuban.ru'" class="flex flex-col gap-8">
                    <a href="mailto:sale@kratonkuban.ru">
                    <div class="flex items-center md:gap-2 text-sm">
                        <x-icon name="email" class="w-5 h-5 " />
                        <div>
                            <span class="hidden md:block">sale@kratonkuban.ru</span>
                        </div>
                    </div>
                    </a>
                </div>
                <div class="border-r border border-zinc-300"></div>
                <div class="flex flex-col gap-8">
                    <div class="flex items-center gap-2 text-sm">
                        <x-tooltip {{-- title="Режим работы:" --}} align="right" subtitle="ПН - Пт: 9:00 - 18:00" subtitle2="Сб-Вс: выходной ">
                            <x-slot:trigger>
                                <x-icon name="info" class="w-5 h-5" />
                            </x-slot:trigger>
                            Любой текст из снипетов в админке
                        </x-tooltip>
                        <div class="whitespace-nowrap hidden xs:block">Режим работы</div>
                    </div>
                </div>
            </div>
            @php
                $primaryLinks = [
                    ['href' => '/', 'label' => 'Оформление и доставка'],
                    ['href' => '/catalog', 'label' => 'Производство'],
                    ['href' => '/catalog', 'label' => 'Сервисное обслуживание'],
                    ['href' => '/contacts', 'label' => 'Контакты'],
                ];
                $links = collect($primaryLinks);
                $lgInlineCount = 2;
                $lgInlineLinks = $links->take($lgInlineCount);
                $lgMoreLinks = $links->slice($lgInlineCount)->values();
            @endphp

            <nav aria-label="Primary" class="flex items-center">

                {{-- <LG : бургер (внутри все пункты) --}}
                <div class="relative lg:hidden" x-data="navDropdown()" @keydown.escape.window="close()">
                    <button type="button" class="inline-flex items-center gap-2 text-sm" @click="toggle()"
                        :aria-expanded="open.toString()" aria-haspopup="true">
                        <x-icon name="menu" class="w-5 h-5" />
                        <span class="hidden md:block">Меню</span>
                    </button>

                    <div x-show="open" @click.outside="close()" x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 translate-y-1"
                        class="absolute right-0 top-full z-30 mt-2 w-72 whitespace-normal border border-zinc-200 bg-white p-2 shadow-lg"
                        style="display:none" role="menu">
                        @foreach ($links as $item)
                            <a href="{{ $item['href'] }}"
                                class="block rounded px-3 py-2 text-sm text-zinc-900 hover:bg-zinc-100" role="menuitem">
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </div>
                </div>

                {{-- LG..XL-1 : первые 2 пункта + "Ещё" --}}
                <ul class="hidden lg:flex xl:hidden items-center gap-6 text-sm whitespace-nowrap">
                    @foreach ($lgInlineLinks as $item)
                        <li><a href="{{ $item['href'] }}">{{ $item['label'] }}</a></li>
                    @endforeach

                    @if ($lgMoreLinks->count())
                        <li class="relative" x-data="navDropdown()" @mouseenter="show()" @mouseleave="hide(150)"
                            @keydown.escape.window="close()">
                            <button type="button" class="inline-flex items-center gap-2" @click="toggle()"
                                :aria-expanded="open.toString()" aria-haspopup="true">
                                Ещё <x-icon name="arrow_down" class="w-3 h-3" />
                            </button>

                            <div x-show="open" @mouseenter="show()" @mouseleave="hide(150)" @click.outside="close()"
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 translate-y-1"
                                x-transition:enter-end="opacity-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-100"
                                x-transition:leave-start="opacity-100 translate-y-0"
                                x-transition:leave-end="opacity-0 translate-y-1"
                                class="absolute right-0 top-full z-20 mt-2 w-64 whitespace-normal border border-zinc-200 bg-white p-2 shadow-lg"
                                style="display:none" role="menu">
                                @foreach ($lgMoreLinks as $item)
                                    <a href="{{ $item['href'] }}"
                                        class="block rounded px-3 py-2 text-sm text-zinc-900 hover:bg-zinc-100"
                                        role="menuitem">
                                        {{ $item['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        </li>
                    @endif
                </ul>

                {{-- XL+ : все пункты --}}
                <ul class="hidden xl:flex items-center gap-6 text-sm whitespace-nowrap">
                    @foreach ($links as $item)
                        <li><a href="{{ $item['href'] }}">{{ $item['label'] }}</a></li>
                    @endforeach
                </ul>

            </nav>
        </div>
    </div>
    <div class="max-w-7xl mx-auto px-6 py-4 flex items-center flex-wrap lg:flex-nowrap justify-between gap-8">
        {{-- LOGO --}}
        <div class="min-w-0 max-w-52 order-0 ">
            <a href="{{ route('home') }}" aria-label="На главную">
                <x-icon name="logo" class="w-full h-auto hidden xs:block" />
                <x-icon name="logo_sq" class="size-14 xs:hidden" />
            </a>
        </div>
        {{-- КАТАЛОГ + ПОИСК --}}
        <div class="flex-1 flex gap-4 min-w-3xs sm:min-w-142 order-2 lg:order-1">
            <button x-data x-tooltip.smart.bottom.offset-10.lt-xl="'КАТАЛОГ'"
                class="flex items-center gap-2 bg-brand-green green hover:bg-[#1c7731] text-white wdth-80 font-bold h-11 px-4 cursor-pointer"><x-icon
                    name="katalog" class="w-5 h-5 text-white" /><span class="hidden xl:block">КАТАЛОГ</span></button>
            <livewire:header.search class="flex-1 min-w-0" />
        </div>
        {{-- ИКОНКИ ПРАВЫЕ --}}
        <div class="grid grid-cols-4 gap-6 xl:gap-4 order-1 lg:oreder-2">
            <div x-data x-tooltip.smart.bottom.offset-10.lt-xl="'Войти'"
                class="flex-1 flex flex-col items-center text-sm cursor-pointer">
                <x-icon name="user" class="size-6 xl:size-5 -translate-y-0.5" />
                <div>
                    <span class="hidden xl:block">Войти</span>
                </div>
            </div>

            <div x-data x-tooltip.smart.bottom.offset-10.lt-xl="'Сравнение'"
                class="flex-1 flex flex-col items-center text-sm cursor-pointer">
                <x-icon name="compare" class="size-6 xl:size-5 -translate-y-0.5" />
                <div>
                    <span class="hidden xl:block">Сравнение</span>
                </div>
            </div>

            <div x-data x-tooltip.smart.bottom.offset-10.lt-xl="'Избранное'"
                class="flex-1 flex flex-col items-center text-sm cursor-pointer">
                <x-icon name="bokmark" class="size-6 xl:size-5 -translate-y-0.5" />
                <div>
                    <span class="hidden xl:block">Избранное</span>
                </div>
            </div>

            <div x-data x-tooltip.smart.bottom.offset-10.lt-xl="'Корзина'"
                class="flex-1 flex flex-col items-center text-sm cursor-pointer">
                <x-icon name="cart" class="size-6 xl:size-5 -translate-y-0.5" />
                <div>
                    <span class="hidden xl:block">Корзина</span>
                </div>
            </div>
        </div>

    </div>
</header>
