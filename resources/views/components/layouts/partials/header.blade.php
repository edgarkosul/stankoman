<header class="">
    <div class="bg-zinc-100 ">
        <div class="flex max-w-7xl m-auto py-2 px-4 gap-16">
            <div class="flex  flex-col gap-8">
                <div class="flex items-center gap-2 text-sm">
                    <x-tooltip {{-- title="Режим работы:" --}} subtitle="г. Краснодар, трасса М4-ДОН">
                        <x-slot:trigger>
                            <x-icon name="spot" class="w-5 h-5" />
                        </x-slot:trigger>
                        пос. Новознаменский, ул. Андреевская, 2
                    </x-tooltip>
                    <div>Краснодар</div>
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
                            <div class="whitespace-nowrap">+7 (900) 246-86-60</div>
                        </a>
                    </div>
                </div>
                <div class="border-r border-1 border-zinc-300"></div>
                <div class="flex flex-col gap-8">
                    <div class="flex items-center gap-2 text-sm">
                        <x-icon name="email" class="w-5 h-5 -translate-y-0.5" />
                        <div>
                            <a href="mailto:sale@kratonkuban.ru">sale@kratonkuban.ru</a>
                        </div>
                    </div>
                </div>
                <div class="border-r border-1 border-zinc-300"></div>
                <div class="flex flex-col gap-8">
                    <div class="flex items-center gap-2 text-sm">
                        <x-tooltip {{-- title="Режим работы:" --}} subtitle="ПН - Пт: 9:00 - 18:00" subtitle2="Сб-Вс: выходной ">
                            <x-slot:trigger>
                                <x-icon name="info" class="w-5 h-5" />
                            </x-slot:trigger>
                            Любой текст из снипетов в админке
                        </x-tooltip>
                        <div class="whitespace-nowrap">Режим работы</div>
                    </div>
                </div>
            </div>
            <nav aria-label="Primary">
                <ul class="flex gap-6 text-sm whitespace-nowrap">
                    <li><a href="/">Оформление и доставка</a></li>
                    <li><a href="/catalog">Производство</a></li>
                    <li><a href="/catalog">Сервисное обслуживание</a></li>
                    <li><a href="/contacts">Контакты</a></li>
                </ul>
            </nav>
        </div>
    </div>
    <div class="max-w-7xl mx-auto px-6 py-4 flex items-center gap-6">
        <div class="min-w-42 max-w-52">
            <a href="{{ route('home') }}" aria-label="На главную">
                <x-icon name="logo" class="w-full h-auto" />
            </a>
        </div>
        <button
            class="flex items-center gap-2 bg-brand-green green hover:bg-[#1c7731] text-white wdth-80 font-bold h-11 px-4 cursor-pointer"><x-icon
                name="katalog" class="w-5 h-5 text-white" />КАТАЛОГ</button>
        <livewire:header.search class="flex-1 min-w-0" />

        <div class="grid grid-cols-4 gap-4">
            <div class="flex-1 flex flex-col items-center text-sm">
                <x-icon name="user" class="w-5 h-5 -translate-y-0.5" />
                <div>
                    <a href="">Войти</a>
                </div>
            </div>
            <div class="flex-1 flex flex-col items-center text-sm">
                <x-icon name="compare" class="w-5 h-5 -translate-y-0.5" />
                <div>
                    <a href="">Сравнение</a>
                </div>
            </div>
            <div class="flex-1 flex flex-col items-center text-sm">
                <x-icon name="bokmark" class="w-5 h-5 -translate-y-0.5" />
                <div>
                    <a href="">Избранное</a>
                </div>
            </div>
            <div class="flex-1 flex flex-col items-center text-sm">
                <x-icon name="cart" class="w-5 h-5 -translate-y-0.5" />
                <div>
                    <a href="">Корзина</a>
                </div>
            </div>
        </div>
    </div>
</header>
