{{--
    Карточка подсказки у кнопки чата: лицо бота, имя и строка приглашения,
    хвостиком к кнопке. Место карточки задаёт `.cl-bubble` того, кто включает.

    Одна разметка на витрину и предпросмотр в админке. На витрине карточка —
    кнопка, открывающая чат, и текст в ней меняет Alpine («Вам ответили»);
    в предпросмотре (`$clickable = false`) это картинка без скриптов.

    Ждёт: $launcher, $face, $avatarUrl, $botName, $botInvite, $botOnline.
--}}
@php
    $tag = $clickable ? 'button' : 'span';
@endphp

<{{ $tag }} @if ($clickable) type="button" @click="show()" @endif
    class="relative flex w-full cursor-pointer items-start gap-3 rounded-2xl bg-white
           py-3 pl-3 pr-9 text-left shadow-xl ring-1 ring-zinc-200 transition
           hover:ring-zinc-300 focus:outline-none focus-visible:ring-2
           focus-visible:ring-brand-green">
    {{--
        Лицо — то же, что на кнопке и в шапке чата. Робот здесь мелкий
        и спокойный: рядом с текстом, который надо прочитать, второй пляшущий
        объект только мешает. Повадки урезаны в mountBubbleMascot().

        Над пилюлей лица и имени нет (`.cl-pill` в app.css): они уже на кнопке.
    --}}
    <span class="cl-bubble-face relative block h-10 w-10 shrink-0">
        <x-support.chat-face :face="$face" :avatar="$avatarUrl" :icon="$launcher->iconPath()" :preview="! $clickable" />
        @if ($botOnline && $launcher->dot !== 'off')
            <span aria-hidden="true"
                  class="cl-bubble-dot absolute -bottom-0.5 -right-0.5 block h-3 w-3 rounded-full
                         bg-emerald-500 ring-2 ring-white"></span>
        @endif
    </span>

    <span class="min-w-0 flex-1">
        <span class="cl-bubble-name block text-sm font-semibold leading-tight text-zinc-900">{{ $botName }}</span>
        <span class="cl-bubble-line mt-1 block text-sm leading-snug text-zinc-600"
              @if ($clickable) x-text="inviteText" @endif>{{ $botInvite }}</span>
    </span>
</{{ $tag }}>

<span aria-hidden="true" class="cl-tail"></span>
