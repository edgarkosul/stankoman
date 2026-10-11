{{--
    Содержимое кнопки чата: лицо (робот, картинка или значок), точка «на связи»,
    имя и строка пилюли. Обёртку с классами вида (`ChatLauncher::classes()`)
    и саму кнопку ставит тот, кто включает.

    Одна разметка на витрину и на предпросмотр в админке — чтобы предпросмотр
    не разошёлся с сайтом. Разница одна, робот: на витрине его вставляет
    скрипт в коробку `x-ref="mascot"` поверх облачка-плейсхолдера, а в
    предпросмотре скриптов нет, и там стоит статичная картинка того же
    артворка.
--}}
@props([
    'launcher',
    'picture' => null,
    'name',
    'preview' => false,
])

<span class="cl-face" aria-hidden="true">
    @if ($launcher->image === \App\Shop\Chat\ChatLauncher::IMAGE_ROBOT)
        <span @unless ($preview) x-ref="mascot" @endunless class="cl-robot-slot @unless ($launcher->floatingRobot()) chat-mascot-slot--sm @endunless">
            @if ($preview)
                <img src="{{ \Illuminate\Support\Facades\Vite::asset('resources/js/mascot/robot-head.svg') }}" alt="" class="chat-mascot">
            @else
                <svg data-mascot-placeholder class="cl-robot-placeholder"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                     stroke-linecap="round" stroke-linejoin="round">
                    {!! \App\Shop\Chat\ChatLauncher::ICON_PATHS['chat'] !!}
                </svg>
            @endif
        </span>
    @elseif ($picture)
        <img src="{{ $picture }}" alt="">
    @else
        <svg class="cl-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
             stroke-linecap="round" stroke-linejoin="round">{!! $launcher->iconPath() !!}</svg>
    @endif
    <span class="cl-dot"></span>
</span>
<span class="cl-text">
    <b class="cl-name">{{ $name }}</b>
    <span class="cl-status">{{ $launcher->status }}</span>
</span>
