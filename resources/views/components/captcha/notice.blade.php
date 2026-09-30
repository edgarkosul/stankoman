{{--
    Уведомление об обработке данных капчей.

    Не оформление, а обязательство: условия SmartCaptcha разрешают убрать
    её угловой блок только в обмен на собственное уведомление. Показывается
    ровно тогда, когда блок спрятан, — решает CaptchaManager::showsNotice().

    Формулировка ужата до двух обязательных частей — названия сервиса
    и ссылки на его условия. Резать дальше нечего: без них уведомление
    перестаёт быть уведомлением.
--}}
@if (app(\App\Services\Captcha\CaptchaManager::class)->showsNotice())
    <p class="mt-2 text-xs text-zinc-500">
        Яндекс SmartCaptcha,
        <a href="https://yandex.ru/legal/smartcaptcha_notice/" target="_blank" rel="noopener nofollow"
            class="underline hover:no-underline">условия обработки данных</a>.
    </p>
@endif
