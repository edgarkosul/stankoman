{{--
    Строка состояния в ленте чата: «печатает», «в работе у менеджера».

    Отдельным компонентом, потому что таких строк несколько и все они об одном —
    «ответа ещё нет, но он готовится». Пока каждая была написана на месте, они
    у донора разъехались: у менеджера получился пузырь в цветах магазина,
    неотличимый от настоящей реплики, и покупатель читал его как сообщение,
    которого на самом деле нет.

    Поэтому вид у всех один и намеренно тише реплик: мельче, серым, таблеткой,
    а не пузырём. Единственное цветное пятно — сама точка: она и есть признак
    жизни.

    Три бегущие точки — знак набора, и ставить его там, где никто не печатает,
    значит обещать ответ через секунду. Поэтому у «в работе» одна медленно
    пульсирующая точка: работа идёт, но текст ещё не пишут.
--}}
@props(['typing' => true])

<div class="flex">
    <div class="flex items-center gap-2 rounded-full bg-zinc-100 px-3 py-1.5 text-xs text-zinc-500 ring-1 ring-zinc-200">
        @if ($typing)
            <span class="flex items-center gap-0.5" aria-hidden="true">
                <span class="chat-typing-dot h-1.5 w-1.5 rounded-full bg-brand-green"></span>
                <span class="chat-typing-dot h-1.5 w-1.5 rounded-full bg-brand-green"></span>
                <span class="chat-typing-dot h-1.5 w-1.5 rounded-full bg-brand-green"></span>
            </span>
        @else
            <span class="h-1.5 w-1.5 shrink-0 animate-pulse rounded-full bg-brand-green" aria-hidden="true"></span>
        @endif

        <span>{{ $slot }}</span>
    </div>
</div>
