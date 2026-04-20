<div @auth
x-data="navDropdown()"
        @keydown.escape.window="close()"
        @mouseenter="show()"
        @mouseleave="hide(150)" @endauth
    class="relative">
    @auth
        <button type="button" @click="toggle()" :aria-expanded="open.toString()" aria-haspopup="menu"
            class="flex flex-col items-center text-sm cursor-pointer" data-test="user-menu-button">
            <x-icon name="user"
                class="size-6 xl:size-5 -translate-y-0.5 [&_.icon-base]:text-zinc-800 [&_.icon-accent]:text-brand-red" />
            <span class="hidden xl:block">{{ str(auth()->user()->name)->before(' ')->limit(11, '') }}</span>
        </button>

        <div x-show="open" @mouseenter="show()" @mouseleave="hide(150)" @click.outside="close()"
            x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-1"
            class="absolute -right-30 top-full z-30 mt-2 w-60 border border-zinc-200 bg-white p-[.3125rem] shadow-sm"
            style="display:none" role="menu" data-test="user-menu-dropdown">
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

            @if ($this->isFilamentAdmin())
                <div class="flex items-center gap-2 px-2 py-1.5 hover:bg-zinc-50">
                    <x-icon name="settings" class="size-6 [&_.icon-base]:text-zinc-800 [&_.icon-accent]:text-brand-red overflow-visible" />
                    <a href="{{ $this->filamentAdminUrl() }}" @click="close()"
                        class="w-full px-2 py-1.5 text-sm font-medium text-zinc-800 hover:bg-zinc-50"
                        role="menuitem" data-test="user-menu-admin-item">
                        Админка
                    </a>
                </div>
            @else
                <div class="flex items-center gap-2 px-2 py-1.5 hover:bg-zinc-50">
                    <x-icon name="cart" class="size-6 [&_.icon-base]:text-zinc-800 [&_.icon-accent]:text-brand-red overflow-visible" />
                    <a href="{{ route('user.orders.index') }}" wire:navigate @click="close()"
                        class=" w-full px-2 py-1.5  font-medium text-zinc-800 " role="menuitem"
                        data-test="user-menu-orders-item">
                        Мои заказы
                    </a>
                </div>
                <div class="flex items-center gap-2 px-2 py-1.5 hover:bg-zinc-50">
                    <x-icon name="settings" class="size-6 [&_.icon-base]:text-zinc-800 [&_.icon-accent]:text-brand-red overflow-visible" />
                    <a href="{{ route('profile.edit') }}" wire:navigate @click="close()"
                        class="w-full px-2 py-1.5  text-sm font-medium text-zinc-800 hover:bg-zinc-50"
                        role="menuitem" data-test="user-menu-settings-item">
                        Настройки
                    </a>
                </div>

                @if ($this->hasUnverifiedEmail())
                    <div class="flex items-center gap-2 px-2 py-1.5 hover:bg-zinc-50">
                        <x-icon name="email_v" class="size-6 [&_.icon-base]:text-zinc-800 [&_.icon-accent]:text-brand-red overflow-visible" />
                        <button type="button" wire:click="openVerifyEmailModal" @click="close()"
                            class="flex w-full items-center rounded-md px-2 py-1.5 text-start text-sm font-medium text-zinc-800 hover:bg-zinc-50"
                            role="menuitem" data-test="user-menu-verify-email-item">
                            Подтвердить email
                        </button>
                    </div>
                @endif
            @endif

            <div class="-mx-[.3125rem] my-[.3125rem] h-px bg-zinc-200"></div>
            <div class="flex items-center gap-2 px-2 py-1.5 hover:bg-zinc-50">
                <x-icon name="logout" class="size-6 [&_.icon-base]:text-zinc-800 [&_.icon-accent]:text-brand-red overflow-visible" />
                <button type="button" wire:click="logout" @click="close()"
                    class="flex w-full items-center rounded-md px-2 py-1.5 text-start text-sm font-medium text-zinc-800 hover:bg-zinc-50"
                    role="menuitem" data-test="user-menu-logout-item">
                    Выйти из аккаунта
                </button>
            </div>
        </div>
    @else
        <button type="button" wire:click="openLoginModal" class="flex flex-col items-center text-sm cursor-pointer"
            data-test="open-login-modal-button">
            <x-icon name="user"
                class="size-6 xl:size-5 -translate-y-0.5 [&_.icon-base]:text-zinc-800 [&_.icon-accent]:text-brand-red" />
            <span class="hidden xl:block">Войти</span>
        </button>
    @endauth
</div>
