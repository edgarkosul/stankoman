@if (!empty($links))
    <nav aria-label="Footer" class="w-full">
        <ul class="flex flex-col gap-2">
            @foreach ($links as $link)
                <li>
                    <a
                        href="{{ $link['href'] }}"
                        @if (!empty($link['target'])) target="{{ $link['target'] }}" @endif
                        @if (!empty($link['rel'])) rel="{{ $link['rel'] }}" @endif
                        class="text-zinc-200 hover:text-white hover:underline"
                    >
                        {{ $link['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
