@props(['title', 'description'])
<div class="flex flex-col gap-2 text-center">
    <div class="flex flex-row items-start gap-6">
        <div class="flex w-full flex-col text-center">
            <flux:heading size="xl" >{{ $title }}</flux:heading>
        </div>
    </div>
    <flux:subheading>{{ $description }}</flux:subheading>
</div>
