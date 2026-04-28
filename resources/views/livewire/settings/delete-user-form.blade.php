@php
    $inputClasses = 'h-11 w-full border border-zinc-300 bg-white px-3 text-sm text-zinc-900 placeholder:text-zinc-400 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30 disabled:cursor-not-allowed disabled:opacity-70';
@endphp

<section
    class="mt-10 border-t border-zinc-200 pt-8"
    x-data="{ confirmDeleteOpen: @js($errors->isNotEmpty()) }"
    @keydown.escape.window="confirmDeleteOpen = false"
>
    <div class="mb-5 space-y-1">
        <h3 class="text-xl font-semibold text-zinc-900">Удаление аккаунта</h3>
        <p class="text-sm text-zinc-600">Удалите аккаунт и все связанные с ним данные</p>
    </div>

    <button
        type="button"
        @click="confirmDeleteOpen = true"
        class="inline-flex h-11 items-center justify-center border border-brand-red px-5 text-sm font-semibold text-brand-red transition hover:bg-brand-red/5"
    >
        Удалить аккаунт
    </button>

    <div
        x-cloak
        x-show="confirmDeleteOpen"
        x-transition.opacity
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="delete-account-title"
    >
        <div
            class="w-full max-w-lg border border-zinc-200 bg-white p-6 shadow-xl"
            @click.outside="confirmDeleteOpen = false"
        >
            <form wire:submit="deleteUser" class="space-y-5">
                <div class="space-y-2">
                    <h4 id="delete-account-title" class="text-lg font-semibold text-zinc-900">
                        Вы уверены, что хотите удалить аккаунт?
                    </h4>
                    <p class="text-sm text-zinc-600">
                        После удаления аккаунта все данные будут удалены безвозвратно. Введите пароль для подтверждения.
                    </p>
                </div>

                <div>
                    <label for="delete-account-password" class="mb-1 block text-sm font-medium text-zinc-700">
                        Пароль
                    </label>
                    <input
                        id="delete-account-password"
                        wire:model="password"
                        type="password"
                        autocomplete="current-password"
                        class="{{ $inputClasses }}"
                    />
                    @error('password')
                        <p class="mt-1 text-sm text-brand-red">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        @click="confirmDeleteOpen = false; $wire.password = ''"
                        class="inline-flex h-11 items-center justify-center border border-zinc-300 px-5 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50"
                    >
                        Отмена
                    </button>

                    <button
                        type="submit"
                        class="inline-flex h-11 items-center justify-center border border-brand-red bg-brand-red px-5 text-sm font-semibold text-white transition hover:bg-brand-red/90 disabled:cursor-not-allowed disabled:opacity-70"
                        wire:loading.attr="disabled"
                        wire:target="deleteUser"
                    >
                        <span wire:loading.remove wire:target="deleteUser">Удалить аккаунт</span>
                        <span wire:loading wire:target="deleteUser">Удаление...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</section>
