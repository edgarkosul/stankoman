{{--
    Лицо бота в подсказке у кнопки и в шапке панели (`ChatAppearance::face()`):
    робот, аватар или значок.

    Отдельный компонент, потому что мест три: подсказка, шапка и предпросмотр
    в админке. Лицо обязано быть одно: три разных лица у одного собеседника
    читаются как три разных сервиса на одной странице.

    Робота сюда вставляет скрипт, поэтому у мест он свой:
      - в подсказке — коробка `x-ref="bubbleMascot"` лаунчера;
      - в шапке — свой компонент `chatAvatar` под `wire:ignore`: Livewire
        на каждом опросе вычищал бы вставленный скриптом SVG;
      - в предпросмотре скриптов нет — статичная картинка артворка.

    Фоны у мест разные: подсказка белая, шапка зелёная.
--}}
@props([
    'face',
    'avatar' => null,
    'icon' => \App\Shop\Chat\ChatLauncher::ICON_PATHS['chat'],
    'variant' => 'bubble',
    'preview' => false,
])

@if ($face === \App\Shop\Chat\ChatAppearance::FACE_ROBOT)
    @if ($preview)
        <span class="chat-mascot-slot--sm relative block h-full w-full">
            <img src="{{ \Illuminate\Support\Facades\Vite::asset('resources/js/mascot/robot-head.svg') }}" alt="" class="chat-mascot">
        </span>
    @elseif ($variant === 'header')
        <span wire:ignore x-data="chatAvatar(40)" class="chat-mascot-slot--sm relative block h-full w-full"></span>
    @else
        <span x-ref="bubbleMascot" class="chat-mascot-slot--sm relative block h-full w-full"></span>
    @endif
@elseif ($face === \App\Shop\Chat\ChatAppearance::FACE_AVATAR && $avatar)
    <img src="{{ $avatar }}" alt="" aria-hidden="true"
         class="block h-full w-full rounded-full bg-white object-cover">
@else
    <span aria-hidden="true"
          class="flex h-full w-full items-center justify-center rounded-full text-white
                 {{ $variant === 'header' ? 'bg-white/15' : 'bg-brand-green' }}">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
             stroke-linecap="round" stroke-linejoin="round">{!! $icon !!}</svg>
    </span>
@endif
