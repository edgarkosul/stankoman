<div class="flex items-start max-md:flex-col">
    <div class="me-10 pb-4 min-w-50">
        <flux:navlist aria-label="'Настройки'">
            <flux:navlist.item :href="route('profile.edit')" class="rounded-none" wire:navigate>Профиль</flux:navlist.item>
            <flux:navlist.item :href="route('user-password.edit')"  class="rounded-none" wire:navigate>{{ __('Password') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full">
            {{ $slot }}
        </div>
    </div>
</div>
