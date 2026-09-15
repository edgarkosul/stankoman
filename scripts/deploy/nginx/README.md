# nginx: защита сайта от краулеров

Два файла из этой папки лежат в `/etc/nginx/conf.d/` на бою, плюс несколько
`location` в `/etc/nginx/sites-available/intertooler.ru`.

| Файл | Что делает |
| --- | --- |
| `intertooler-print-bots.conf` | карта `$intertooler_bad_print_bot` — известные краулеры, которым нельзя в генератор PDF |
| `intertooler-hardening.conf` | карта `$intertooler_clean_uri` для дублей под `/index.php/` и все зоны `limit_req`: сайт, генератор PDF, эндпоинт Livewire, опрос бейджа чата |

## Установка карт

```
scp scripts/deploy/nginx/intertooler-*.conf edgar@intertooler-production:/tmp/
ssh edgar@intertooler-production \
    'sudo install -m 644 -o root -g root /tmp/intertooler-*.conf /etc/nginx/conf.d/'
```

## Что должно быть во вхосте

В блоке `server { server_name intertooler.ru; ... }`, **выше** `location / {`:

```nginx
# Тяжёлый генератор PDF — известным краулерам сюда нельзя.
# Регулярка намеренно без префикса: боты ходили сюда через /index.php/...
# и правило, описанное от корня, их не ловило.
location ~ /print$ {
    if ($intertooler_bad_print_bot) {
        return 403;
    }

    limit_req zone=intertooler_print burst=5 nodelay;

    try_files $uri $uri/ /index.php?$query_string;
}

# /index.php/... — вторая копия каталога для поисковиков и обход правил
# выше. Уводим на чистый адрес.
location ^~ /index.php/ {
    return 301 $intertooler_clean_uri;
}

# Единственный эндпоинт всех действий Livewire: корзина, фильтры каталога,
# подсказки поиска, вопрос боту. Путь считается от APP_KEY, поэтому
# регулярка, а не литерал: на деве и на бою хэш разный. Кавычки
# обязательны — без них nginx спотыкается о {8} в регулярке.
location ~ "^/livewire-[0-9a-f]{8}/update$" {
    limit_req zone=intertooler_livewire burst=40 nodelay;

    try_files $uri $uri/ /index.php?$query_string;
}

# Опрос бейджа «вам ответили» у свёрнутой панели чата.
location = /chat/unread {
    limit_req zone=intertooler_chat burst=10 nodelay;

    try_files $uri $uri/ /index.php?$query_string;
}
```

Зачем зоны на эндпоинты, если те же потолки стоят в приложении: запрос без
CSRF-токена Laravel отбивает 419 **до** своего `throttle` (`VerifyCsrfToken`
в группе `web` стоит раньше), то есть поток мусорных POST его счётчик
не считает вовсе, а воркер php-fpm на каждый такой запрос тратится.

И в самом `location / {`:

```nginx
limit_req zone=intertooler_site burst=20 nodelay;
```

Применить: `sudo nginx -t && sudo systemctl reload nginx`.

## Проверка

```
S=/product/<slug>
curl -s -o /dev/null -w '%{http_code}\n' "https://intertooler.ru$S"                 # 200
curl -s -o /dev/null -D- "https://intertooler.ru/index.php$S" | grep -i ^location    # 301 на чистый
curl -s -o /dev/null -w '%{http_code}\n' -A Bytespider "https://intertooler.ru$S/print"  # 403

# лимит частоты: локалхост исключён, обычный адрес упирается в 429
for i in $(seq 1 40); do curl -sk -o /dev/null -w '%{http_code} ' \
    --resolve intertooler.ru:443:127.0.0.1 "https://intertooler.ru/rate-test-$i"; done
for i in $(seq 1 40); do curl -s -o /dev/null -w '%{http_code} ' \
    "https://intertooler.ru/rate-test-$i"; done
```

Зоны чата и Livewire — там же, но помнить про две вещи. Путь Livewire берётся
из разметки страницы (`grep -o 'livewire-[0-9a-f]\{8\}/update'`), а не
угадывается: он считается от `APP_KEY`. И с локалхоста лимит не проверяется
вовсе — оттуда ходит резолвер legacy-редиректов, и ключ там намеренно пустой,
поэтому проверять только снаружи.

```
L=$(curl -s https://intertooler.ru/ | grep -o 'livewire-[0-9a-f]\{8\}/update' | head -1)
for i in $(seq 1 45); do curl -s -o /dev/null -w '%{http_code} ' \
    -X POST "https://intertooler.ru/$L"; done; echo   # 419… потом 429
for i in $(seq 1 15); do curl -s -o /dev/null -w '%{http_code} ' \
    "https://intertooler.ru/chat/unread"; done; echo   # 200… потом 429
```

**Лимит обязан считаться по адресу, а не на всех сразу.** Двух проверок выше
для этого мало: они прошли и на конфиге, где из-за `geo` ключ у всех клиентов
был одной и той же строкой, — один общий бакет на весь сайт (инцидент
08.09.2026). Нужен второй источник: заливаем лимит с сервера и одновременно
стучимся снаружи — со своей машины, не по SSH.

```
# на сервере: выбираем бакет его внешнего адреса
ssh edgar@intertooler-production \
    'for i in $(seq 1 60); do curl -s -o /dev/null -w "%{http_code} " \
        "https://intertooler.ru/rate-test-$i"; done; echo'

# со своей машины, пока идёт цикл выше, — должно быть 200, а не 429
curl -s -o /dev/null -w '%{http_code}\n' https://intertooler.ru/
```

## Что закрыто на стороне приложения

- `throttle:120,1` на эндпоинте Livewire (`AppServiceProvider::throttleLivewireUpdates`)
  и `throttle:60,1` на `/chat/unread`;
- потолки чата — пауза между вопросами, вопросы на разговор в час и в сутки,
  обращения и новые разговоры с адреса, дневной потолок магазина
  ([ChatAbuseGuard](../../../app/Services/Chat/ChatAbuseGuard.php));
- Яндекс SmartCaptcha на первом сообщении разговора
  ([CaptchaManager](../../../app/Services/Captcha/CaptchaManager.php));
- готовый PDF кладётся в `storage/app/private/pdf-offers` и дальше отдаётся с диска —
  сборка стоила 16-18 секунд php-fpm воркера ([ProductPrintController](../../../app/Http/Controllers/ProductPrintController.php));
- `throttle:12,1` на маршруте `product.print`;
- заголовок `X-Robots-Tag: noindex, nofollow` на самом PDF;
- `Disallow: /*/print` в генераторе `robots.txt` (`php artisan sitemap:generate`);
- `rel="nofollow"` на обеих ссылках в карточке товара.

Кэш инвалидируется сам: ключ считается от товара, его значений и опций
атрибутов, реквизитов из настроек и содержимого шаблона оферты. На товар
хранится один файл, прежние версии удаляются при пересборке.
