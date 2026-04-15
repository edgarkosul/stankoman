@if (filled($yandexMetrikaId = config('services.yandex_metrika.id')))
    <noscript>
        <div>
            <img
                src="https://mc.yandex.ru/watch/{{ urlencode((string) $yandexMetrikaId) }}"
                style="position:absolute; left:-9999px;"
                alt=""
            />
        </div>
    </noscript>
@endif
