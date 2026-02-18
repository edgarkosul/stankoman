<footer class="bg-zinc-700 text-white">
    <div
        class="mx-auto grid max-w-7xl grid-cols-1 gap-6 px-4 py-4 xs:py-6 md:grid-cols-2 md:gap-8 md:px-6 lg:grid-cols-3 2xl:grid-cols-[1fr_2fr_1fr]">
        <div class="flex flex-col items-start space-y-6 md:col-span-2 lg:col-span-1">
            <a href="{{ route('home') }}" aria-label="На главную" class="block w-full max-w-72">
                <x-icon name="logo"
                    class="h-auto w-full [&_.brand-gray]:text-white [&_.white]:text-zinc-700 [&_.brand-red]:text-white [&_.brand-dark]:text-white" />
            </a>
            <span>Профессиональный инструмент и оборудование</span>
            <span>ПН - Пт: 9:00 - 18:00
                Сб-Вс: выходной</span>
        </div>

        <div class="lg:justify-self-center">
            <x-footer-menu menu-key="footer" />
        </div>

        <div class="md:justify-self-end">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col gap-4 items-start md:items-end">
                    <a href="https://max.ru/" target="_blank" class="flex items-center gap-2">
                        <x-icon name="max"
                            class="w-5 h-5 [&_.icon-base]:text-white [&_.icon-accent]:text-brand-red" /> Max
                    </a>
                    <a href="tg://resolve?phone=79002468660" class="flex items-center gap-2">
                        <x-icon name="telegram"
                            class="w-5 h-5 [&_.icon-base]:text-white [&_.icon-accent]:text-brand-red" />Telegram
                    </a>
                    <a href="tel:+79002468660" class="flex gap-2">
                        <x-icon name="phone"
                            class="w-5 h-5 [&_.icon-base]:text-white [&_.icon-accent]:text-brand-red" />
                        <span class="whitespace-nowrap ">+7 (900) 246-86-60</span>
                    </a>
                    <a href="mailto:sale@kratonkuban.ru">
                        <div class="flex items-center md:gap-2">
                            <x-icon name="email"
                                class="w-5 h-5 [&_.icon-base]:text-white [&_.icon-accent]:text-brand-red" />
                            <div>
                                <span class="">sale@kratonkuban.ru</span>
                            </div>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="max-w-7xl mx-auto px-4 md:px-6 py-4">
        <p class="border-t border-zinc-600 py-4 flex gap-4">© {{ now()->year }} <a href="{{ url('/') }}"
                class="hover:underline underline-offset-4">StankoMan.ru</a> Все права защищены</p>
    </div>
</footer>
