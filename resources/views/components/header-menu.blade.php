<nav aria-label="Primary" class="flex items-center">

    {{-- <LG : бургер (внутри все пункты) --}}
    <div class="relative lg:hidden" x-data="navDropdown()" @keydown.escape.window="close()">
        <button type="button" class="flex items-center gap-2 text-sm" @click="toggle()"
            :aria-expanded="open.toString()" aria-haspopup="true">
            <x-icon name="menu" class="w-5 h-5" />
            <div class="hidden md:block">Меню</div>
        </button>

        <div x-show="open" @click.outside="close()" x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 translate-y-1"
            class="absolute right-0 top-full z-30 mt-2 w-72 whitespace-normal border border-zinc-200 bg-white p-2 shadow-lg"
            style="display:none" role="menu">
            @foreach ($links as $item)
                @if (count($item['children']))
                    <div x-data="{ open: false }"
                        x-effect="if ($refs.panel) { $refs.panel.style.maxHeight = open ? $refs.panel.scrollHeight + 'px' : '0px'; }"
                        class="rounded">
                        <button type="button"
                            class="flex w-full items-center gap-2 rounded px-3 py-2 text-left text-sm text-zinc-900 hover:bg-zinc-100"
                            @click="open = !open" :aria-expanded="open.toString()"
                            aria-controls="menu-mobile-{{ $item['id'] }}">
                            <span class="flex-1 {{ $item['is_active'] ? 'text-brand-green' : '' }}">
                                {{ $item['label'] }}
                            </span>
                            <x-icon name="arrow_down" class="w-3 h-3 transition-transform"
                                x-bind:class="{ 'rotate-180': open }" />
                        </button>
                        <div id="menu-mobile-{{ $item['id'] }}" x-ref="panel"
                            class="overflow-hidden transition-all duration-200 ease-out" style="max-height: 0px;">
                            <div class="space-y-1 pb-2 pl-6">
                                @foreach ($item['children'] as $child)
                                    <a href="{{ $child['href'] }}"
                                        class="block rounded px-3 py-2 text-sm text-zinc-900 hover:bg-zinc-100 {{ $child['is_active'] ? 'text-brand-green' : '' }}"
                                        role="menuitem">
                                        {{ $child['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @else
                    <a href="{{ $item['href'] }}"
                        class="block rounded px-3 py-2 text-sm text-zinc-900 hover:bg-zinc-100 {{ $item['is_active'] ? 'text-brand-green' : '' }}"
                        role="menuitem">
                        {{ $item['label'] }}
                    </a>
                @endif
            @endforeach
        </div>
    </div>

    {{-- LG..XL-1 : первые 2 пункта + "Ещё" --}}
    <ul class="hidden lg:flex xl:hidden items-center gap-6 text-sm whitespace-nowrap">
        @foreach ($lgInlineLinks as $item)
            @if (count($item['children']))
                <li class="relative" x-data="navDropdown()" @mouseenter="show()" @mouseleave="hide(150)"
                    @keydown.escape.window="close()">
                    <span class="inline-flex items-center gap-2 {{ $item['is_active'] ? 'text-brand-green' : '' }}">
                        {{ $item['label'] }} <x-icon name="arrow_down" class="w-3 h-3" />
                    </span>

                    <div x-show="open" @mouseenter="show()" @mouseleave="hide(150)" @click.outside="close()"
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 translate-y-1"
                        class="absolute right-0 top-full z-20 mt-2 w-64 whitespace-normal border border-zinc-200 bg-white p-2 shadow-lg"
                        style="display:none" role="menu">
                        @foreach ($item['children'] as $child)
                            <a href="{{ $child['href'] }}"
                                class="block rounded px-3 py-2 text-sm text-zinc-900 hover:bg-zinc-100 {{ $child['is_active'] ? 'text-brand-green' : '' }}"
                                role="menuitem">
                                {{ $child['label'] }}
                            </a>
                        @endforeach
                    </div>
                </li>
            @else
                <li>
                    <a href="{{ $item['href'] }}"
                        class="{{ $item['is_active'] ? 'text-brand-green' : '' }}">
                        {{ $item['label'] }}
                    </a>
                </li>
            @endif
        @endforeach

        @if ($lgMoreLinks->count())
            <li class="relative" x-data="navDropdown()" @mouseenter="show()" @mouseleave="hide(150)"
                @keydown.escape.window="close()">
                <button type="button" class="inline-flex items-center gap-2" @click="toggle()"
                    :aria-expanded="open.toString()" aria-haspopup="true">
                    Ещё <x-icon name="arrow_down" class="w-3 h-3" />
                </button>

                <div x-show="open" @mouseenter="show()" @mouseleave="hide(150)" @click.outside="close()"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 translate-y-1"
                    class="absolute right-0 top-full z-20 mt-2 w-64 whitespace-normal border border-zinc-200 bg-white p-2 shadow-lg"
                    style="display:none" role="menu">
                    @foreach ($lgMoreLinks as $item)
                        @if (count($item['children']))
                            <div x-data="{ open: false }"
                                x-effect="if ($refs.panel) { $refs.panel.style.maxHeight = open ? $refs.panel.scrollHeight + 'px' : '0px'; }"
                                class="rounded">
                                <button type="button"
                                    class="flex w-full items-center gap-2 rounded px-3 py-2 text-left text-sm text-zinc-900 hover:bg-zinc-100"
                                    @click="open = !open" :aria-expanded="open.toString()"
                                    aria-controls="menu-more-{{ $item['id'] }}">
                                    <span class="flex-1 {{ $item['is_active'] ? 'text-brand-green' : '' }}">
                                        {{ $item['label'] }}
                                    </span>
                                    <x-icon name="arrow_down" class="w-3 h-3 transition-transform"
                                        x-bind:class="{ 'rotate-180': open }" />
                                </button>
                                <div id="menu-more-{{ $item['id'] }}" x-ref="panel"
                                    class="overflow-hidden transition-all duration-200 ease-out"
                                    style="max-height: 0px;">
                                    <div class="space-y-1 pb-2 pl-6">
                                        @foreach ($item['children'] as $child)
                                            <a href="{{ $child['href'] }}"
                                                class="block rounded px-3 py-2 text-sm text-zinc-900 hover:bg-zinc-100 {{ $child['is_active'] ? 'text-brand-green' : '' }}"
                                                role="menuitem">
                                                {{ $child['label'] }}
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @else
                            <a href="{{ $item['href'] }}"
                                class="block rounded px-3 py-2 text-sm text-zinc-900 hover:bg-zinc-100 {{ $item['is_active'] ? 'text-brand-green' : '' }}"
                                role="menuitem">
                                {{ $item['label'] }}
                            </a>
                        @endif
                    @endforeach
                </div>
            </li>
        @endif
    </ul>

    {{-- XL+ : первые 4 пункта + "Ещё" --}}
    <ul class="hidden xl:flex items-center gap-4 text-sm whitespace-nowrap">
        @foreach ($xlInlineLinks as $item)
            @if (count($item['children']))
                <li class="relative" x-data="navDropdown()" @mouseenter="show()" @mouseleave="hide(150)"
                    @keydown.escape.window="close()">
                    <span class="inline-flex items-center gap-2 {{ $item['is_active'] ? 'text-brand-green' : '' }}">
                        {{ $item['label'] }} <x-icon name="arrow_down" class="w-3 h-3" />
                    </span>

                    <div x-show="open" @mouseenter="show()" @mouseleave="hide(150)" @click.outside="close()"
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 translate-y-1"
                        class="absolute right-0 top-full z-20 mt-2 w-64 whitespace-normal border border-zinc-200 bg-white p-2 shadow-lg"
                        style="display:none" role="menu">
                        @foreach ($item['children'] as $child)
                            <a href="{{ $child['href'] }}"
                                class="block rounded px-3 py-2 text-sm text-zinc-900 hover:bg-zinc-100 {{ $child['is_active'] ? 'text-brand-green' : '' }}"
                                role="menuitem">
                                {{ $child['label'] }}
                            </a>
                        @endforeach
                    </div>
                </li>
            @else
                <li>
                    <a href="{{ $item['href'] }}"
                        class="{{ $item['is_active'] ? 'text-brand-green' : '' }}">
                        {{ $item['label'] }}
                    </a>
                </li>
            @endif
        @endforeach

        @if ($xlMoreLinks->count())
            <li class="relative" x-data="navDropdown()" @mouseenter="show()" @mouseleave="hide(150)"
                @keydown.escape.window="close()">
                <button type="button" class="inline-flex items-center gap-2" @click="toggle()"
                    :aria-expanded="open.toString()" aria-haspopup="true">
                    Ещё <x-icon name="arrow_down" class="w-3 h-3" />
                </button>

                <div x-show="open" @mouseenter="show()" @mouseleave="hide(150)" @click.outside="close()"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 translate-y-1"
                    class="absolute right-0 top-full z-20 mt-2 w-64 whitespace-normal border border-zinc-200 bg-white p-2 shadow-lg"
                    style="display:none" role="menu">
                    @foreach ($xlMoreLinks as $item)
                        @if (count($item['children']))
                            <div x-data="{ open: false }"
                                x-effect="if ($refs.panel) { $refs.panel.style.maxHeight = open ? $refs.panel.scrollHeight + 'px' : '0px'; }"
                                class="rounded">
                                <button type="button"
                                    class="flex w-full items-center gap-2 rounded px-3 py-2 text-left text-sm text-zinc-900 hover:bg-zinc-100"
                                    @click="open = !open" :aria-expanded="open.toString()"
                                    aria-controls="menu-xl-more-{{ $item['id'] }}">
                                    <span class="flex-1 {{ $item['is_active'] ? 'text-brand-green' : '' }}">
                                        {{ $item['label'] }}
                                    </span>
                                    <x-icon name="arrow_down" class="w-3 h-3 transition-transform"
                                        x-bind:class="{ 'rotate-180': open }" />
                                </button>
                                <div id="menu-xl-more-{{ $item['id'] }}" x-ref="panel"
                                    class="overflow-hidden transition-all duration-200 ease-out"
                                    style="max-height: 0px;">
                                    <div class="space-y-1 pb-2 pl-6">
                                        @foreach ($item['children'] as $child)
                                            <a href="{{ $child['href'] }}"
                                                class="block rounded px-3 py-2 text-sm text-zinc-900 hover:bg-zinc-100 {{ $child['is_active'] ? 'text-brand-green' : '' }}"
                                                role="menuitem">
                                                {{ $child['label'] }}
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @else
                            <a href="{{ $item['href'] }}"
                                class="block rounded px-3 py-2 text-sm text-zinc-900 hover:bg-zinc-100 {{ $item['is_active'] ? 'text-brand-green' : '' }}"
                                role="menuitem">
                                {{ $item['label'] }}
                            </a>
                        @endif
                    @endforeach
                </div>
            </li>
        @endif
    </ul>

</nav>
