<form role="search" aria-label="Поиск по каталогу товаров" class="relative {{ $class }}">
    <label for="header-search-q" class="sr-only">Поиск по каталогу товаров</label>

    <input
        id="header-search-q"
        name="q"
        type="search"
        autocomplete="off"
        placeholder="Поиск по каталогу товаров"
        class="w-full border-brand-green border-2 pl-3 pr-10 h-11 outline-none focus:ring-2 focus:ring-brand-green"
    />

    <button
        type="submit"
        class="absolute inset-y-0 right-0 w-14 grid place-items-center rounded-r-md bg-brand-green hover:bg-[#1c7731] focus:outline-none focus:ring-2 focus:ring-brand-green cursor-pointer"
        aria-label="Найти"
        title="Найти"
    >
        <x-icon name="lupa" class="w-5 h-5 text-white" />
    </button>
</form>
