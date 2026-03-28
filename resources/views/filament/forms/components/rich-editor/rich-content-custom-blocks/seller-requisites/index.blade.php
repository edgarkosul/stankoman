@php
    $title = $title ?? 'Продавец / Администрация сайта';
@endphp

<div class="fi-not-prose my-6 text-zinc-700">
    <div class="grid gap-4">
        <div class="grid gap-1">
            <div class="text-[11px] font-semibold uppercase tracking-[0.22em] text-zinc-500">
                {{ $title }}:
            </div>

            @if ($legalName)
                <div class="text-base font-semibold text-zinc-900">
                    {{ $legalName }}
                </div>
            @endif
        </div>

        <dl class="grid gap-3">
            @if ($inn || $ogrn || $ogrnip)
                <div class="grid gap-1">
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Регистрационные данные</dt>
                    <dd class="flex flex-wrap gap-x-3 gap-y-1 text-zinc-800">
                        @if ($inn)
                            <span>ИНН: {{ $inn }}</span>
                        @endif

                        @if ($ogrn)
                            <span>ОГРН: {{ $ogrn }}</span>
                        @endif

                        @if ($ogrnip)
                            <span>ОГРНИП: {{ $ogrnip }}</span>
                        @endif
                    </dd>
                </div>
            @endif

            @if ($legalAddress)
                <div class="grid gap-1">
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Юридический адрес</dt>
                    <dd class="text-zinc-800">{{ $legalAddress }}</dd>
                </div>
            @endif

            @if ($correspondenceAddress)
                <div class="grid gap-1">
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Адрес для корреспонденции</dt>
                    <dd class="text-zinc-800">{{ $correspondenceAddress }}</dd>
                </div>
            @endif

            @if ($email)
                <div class="grid gap-1">
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Email</dt>
                    <dd>
                        <a
                            href="{{ $emailHref }}"
                            class="text-zinc-900 underline decoration-zinc-300 underline-offset-4 transition hover:decoration-zinc-500"
                        >
                            {{ $email }}
                        </a>
                    </dd>
                </div>
            @endif

            @if ($phone)
                <div class="grid gap-1">
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Телефон</dt>
                    <dd>
                        <a
                            href="{{ $phoneHref }}"
                            class="text-zinc-900 underline decoration-zinc-300 underline-offset-4 transition hover:decoration-zinc-500"
                        >
                            {{ $phone }}
                        </a>
                    </dd>
                </div>
            @endif
        </dl>
    </div>
</div>
