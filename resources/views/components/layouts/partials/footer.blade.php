@php
    $companyBrandLine = trim((string) config('company.brand_line'));
    $companySiteUrl = trim((string) config('company.site_url', url('/')));
    $companySiteHost = trim((string) config('company.site_host')) ?: preg_replace('#^https?://#', '', $companySiteUrl);
    $companyPhone = trim((string) config('company.phone'));
    $companyPhoneHref = preg_replace('/\D+/', '', $companyPhone) ?? '';
    $companyPublicEmail = trim((string) config('company.public_email', config('mail.from.address')));
@endphp

<footer class="bg-zinc-700 text-white">
    <div
        class="mx-auto grid max-w-7xl grid-cols-1 gap-6 px-4 py-4 xs:py-6 md:grid-cols-2 md:gap-8 md:px-6 lg:grid-cols-3 2xl:grid-cols-[1fr_2fr_1fr]">
        <div class="flex flex-col items-start space-y-6 md:col-span-2 lg:col-span-1">
            <a href="{{ $companySiteUrl !== '' ? $companySiteUrl : route('home') }}" aria-label="На главную" class="block w-full max-w-72">
                <x-icon name="logo"
                    class="h-auto w-full [&_.brand-gray]:text-white [&_.white]:text-zinc-700 [&_.brand-red]:text-white [&_.brand-dark]:text-white"
                    style="--logo-dot-color: #404040;" />
            </a>
            @if (filled($companyBrandLine))
                <span class="text-lg font-medium">{{ $companyBrandLine }}</span>
            @endif
            @if (filled($companySiteHost))
                <a href="{{ $companySiteUrl }}" class="text-sm underline-offset-4 hover:underline">{{ $companySiteHost }}</a>
            @endif
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
                    <a href="tg://resolve?phone={{ $companyPhoneHref }}" class="flex items-center gap-2">
                        <x-icon name="telegram"
                            class="w-5 h-5 [&_.icon-base]:text-white [&_.icon-accent]:text-brand-red" />Telegram
                    </a>
                    <a href="tel:+{{ $companyPhoneHref }}" class="flex gap-2">
                        <x-icon name="phone"
                            class="w-5 h-5 [&_.icon-base]:text-white [&_.icon-accent]:text-brand-red" />
                        <span class="whitespace-nowrap ">{{ $companyPhone }}</span>
                    </a>
                    @if (filled($companyPublicEmail))
                        <a href="mailto:{{ $companyPublicEmail }}">
                            <div class="flex items-center md:gap-2">
                                <x-icon name="email"
                                    class="w-5 h-5 [&_.icon-base]:text-white [&_.icon-accent]:text-brand-red" />
                                <div>
                                    <span class="">{{ $companyPublicEmail }}</span>
                                </div>
                            </div>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="max-w-7xl mx-auto px-4 md:px-6 py-4">
        <p class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-white/15 py-4 text-sm text-white/80">
            <span>© {{ now()->year }}</span>
            <a href="{{ $companySiteUrl !== '' ? $companySiteUrl : url('/') }}"
                class="text-white/90 underline-offset-4 transition hover:text-white hover:underline">{{ $companySiteHost }}</a>
            <span>Все права защищены</span>

            <a href="https://www.siteko.net/development#portfolio"
                class="group inline-flex items-center gap-2 text-white/80 transition hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/50 focus-visible:ring-offset-2 focus-visible:ring-offset-zinc-700 md:ml-auto"
                target="_blank"
                rel="noopener">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-white/10 text-white/80 ring-1 ring-white/15 transition group-hover:bg-white group-hover:text-brand-red">
                    <x-heroicon-m-arrow-up-right class="h-4 w-4" />
                </span>
                <span>
                    Laravel-разработка сайта с нуля —
                    <span class="font-semibold text-white">Siteko</span>
                </span>
            </a>
        </p>
    </div>
</footer>
