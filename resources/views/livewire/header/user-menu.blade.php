<div
    @auth
        x-data="{ open: false }"
        @keydown.escape.window="open = false"
        @mouseenter="open = true"
        @mouseleave="open = false"
    @endauth
    class="relative"
>
    @auth
        <button
            type="button"
            @click="open = ! open"
            :aria-expanded="open.toString()"
            aria-haspopup="menu"
            class="flex flex-col items-center text-sm cursor-pointer"
            data-test="user-menu-button"
        >
            <x-icon name="user" class="size-6 xl:size-5 -translate-y-0.5 [&_.icon-base]:text-zinc-800 [&_.icon-accent]:text-brand-red" />
            <span class="hidden xl:block">{{ str(auth()->user()->name)->before(' ')->limit(11, '') }}</span>
        </button>

        <div
            x-show="open"
            @click.outside="open = false"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 translate-y-1"
            class="absolute right-0 top-full z-30 mt-2 w-60 border border-zinc-200 bg-white p-[.3125rem] shadow-sm"
            style="display:none"
            role="menu"
            data-test="user-menu-dropdown"
        >
            <div class="p-2">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 items-center justify-center bg-zinc-200 text-sm font-semibold text-zinc-700">
                        {{ auth()->user()->initials() }}
                    </span>
                    <div class="text-sm leading-tight">
                        <div class="font-semibold">{{ auth()->user()->name }}</div>
                        <div class="text-xs text-zinc-500">{{ auth()->user()->email }}</div>
                    </div>
                </div>
            </div>

            <div class="-mx-[.3125rem] my-[.3125rem] h-px bg-zinc-200"></div>

            <a
                href="{{ route('profile.edit') }}"
                wire:navigate
                @click="open = false"
                class="flex w-full items-center rounded-md px-2 py-1.5 text-start text-sm font-medium text-zinc-800 hover:bg-zinc-50"
                role="menuitem"
                data-test="user-menu-settings-item"
            >
                {{ __('Settings') }}
            </a>

            @if ($this->hasUnverifiedEmail())
                <button
                    type="button"
                    wire:click="openVerifyEmailModal"
                    @click="open = false"
                    class="flex w-full items-center rounded-md px-2 py-1.5 text-start text-sm font-medium text-zinc-800 hover:bg-zinc-50"
                    role="menuitem"
                    data-test="user-menu-verify-email-item"
                >
                    {{ __('Verify email') }}
                </button>
            @endif

            <div class="-mx-[.3125rem] my-[.3125rem] h-px bg-zinc-200"></div>

            <button
                type="button"
                wire:click="logout"
                @click="open = false"
                class="flex w-full items-center rounded-md px-2 py-1.5 text-start text-sm font-medium text-zinc-800 hover:bg-zinc-50"
                role="menuitem"
                data-test="user-menu-logout-item"
            >
                {{ __('Log out') }}
            </button>
        </div>
    @else
        <button
            type="button"
            wire:click="openLoginModal"
            class="flex flex-col items-center text-sm cursor-pointer"
            data-test="open-login-modal-button"
        >
            <x-icon name="user" class="size-6 xl:size-5 -translate-y-0.5 [&_.icon-base]:text-zinc-800 [&_.icon-accent]:text-brand-red" />
            <span class="hidden xl:block">Войти</span>
        </button>
    @endauth
</div>
