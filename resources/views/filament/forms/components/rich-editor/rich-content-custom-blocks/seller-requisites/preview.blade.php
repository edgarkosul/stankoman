@php
    $title = $title ?? 'Продавец / Администрация сайта';
@endphp

<div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900/40">
    <div class="grid gap-3">
        <div class="text-[11px] font-semibold uppercase tracking-[0.22em] text-zinc-500 dark:text-zinc-400">
            {{ $title }}:
        </div>

        <div class="grid gap-2 text-sm text-zinc-700 dark:text-zinc-200">
            @if ($legalName)
                <div class="font-semibold text-zinc-900 dark:text-white">
                    {{ $legalName }}
                </div>
            @endif

            @if ($inn || $ogrn || $ogrnip)
                <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-zinc-600 dark:text-zinc-300">
                    @if ($inn)
                        <span>ИНН: {{ $inn }}</span>
                    @endif

                    @if ($ogrn)
                        <span>ОГРН: {{ $ogrn }}</span>
                    @endif

                    @if ($ogrnip)
                        <span>ОГРНИП: {{ $ogrnip }}</span>
                    @endif
                </div>
            @endif

            @if ($legalAddress)
                <div class="text-xs text-zinc-600 dark:text-zinc-300">
                    {{ $legalAddress }}
                </div>
            @endif

            @if ($correspondenceAddress)
                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                    Адрес для корреспонденции: {{ $correspondenceAddress }}
                </div>
            @endif

            @if ($email || $phone)
                <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                    @if ($email)
                        <span>{{ $email }}</span>
                    @endif

                    @if ($phone)
                        <span>{{ $phone }}</span>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
