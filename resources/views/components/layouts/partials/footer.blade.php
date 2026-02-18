<footer class="bg-zinc-700 text-white">
    <div
        class="mx-auto grid max-w-7xl grid-cols-1 gap-6 px-2 py-8 xs:px-3 sm:px-4 md:grid-cols-2 md:gap-8 md:px-6 lg:grid-cols-3 2xl:grid-cols-[1fr_2fr_1fr]">
        <div class="md:hidden lg:block">
            <a href="{{ route('home') }}" aria-label="На главную" class="block">
                <x-icon name="logo"
                    class="hidden h-auto w-full xs:block [&_.brand-gray]:text-white [&_.white]:text-zinc-700 [&_.brand-red]:text-white [&_.brand-dark]:text-white" />
            </a>
        </div>

        <div class="lg:justify-self-center">
            <x-footer-menu menu-key="footer" />
        </div>

        <div>
            <div class="flex flex-col gap-8">
                <div class="flex flex-col gap-4">
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
        </div>
    </div>
</footer>
