@php
    $companyBrandLine = trim((string) config('company.brand_line'));
    $companyLegalName = trim((string) config('company.legal_name'));
    $companySiteUrl = trim((string) config('company.site_url', url('/')));
    $companySiteHost = trim((string) config('company.site_host')) ?: preg_replace('#^https?://#', '', $companySiteUrl);
    $companyPhone = trim((string) config('company.phone'));
    $companyPhoneHref = preg_replace('/\D+/', '', $companyPhone) ?? '';
    $companyPublicEmail = trim((string) config('company.public_email', config('mail.from.address')));
    $companyLegalAddress = trim((string) config('company.legal_addr'));
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
            @if (filled($companyLegalName))
                <span class="max-w-sm text-sm text-zinc-300">{{ $companyLegalName }}</span>
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
                    @if (filled($companyLegalAddress))
                        <div class="max-w-sm text-sm text-zinc-300 md:text-right">{{ $companyLegalAddress }}</div>
                    @endif
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
        <p class="border-t border-zinc-600 py-4 flex flex-wrap gap-4">
            © {{ now()->year }}
            <a href="{{ $companySiteUrl !== '' ? $companySiteUrl : url('/') }}"
                class="hover:underline underline-offset-4">{{ $companySiteHost }}</a>
            @if (filled($companyLegalName))
                <span class="text-zinc-300">{{ $companyLegalName }}</span>
            @endif
            Все права защищены
        </p>
    </div>
</footer>
