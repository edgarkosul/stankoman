# nginx: защита сайта от краулеров

Два файла из этой папки лежат в `/etc/nginx/conf.d/` на бою, плюс несколько
`location` в `/etc/nginx/sites-available/intertooler.ru`.

| Файл | Что делает |
| --- | --- |
| `intertooler-print-bots.conf` | карта `$intertooler_bad_print_bot` — известные краулеры, которым нельзя в генератор PDF |
| `intertooler-hardening.conf` | карта `$intertooler_clean_uri` для дублей под `/index.php/` и зоны `limit_req` |

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
```

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

## Что закрыто на стороне приложения

- готовый PDF кладётся в `storage/app/private/pdf-offers` и дальше отдаётся с диска —
  сборка стоила 16-18 секунд php-fpm воркера ([ProductPrintController](../../app/Http/Controllers/ProductPrintController.php));
- `throttle:12,1` на маршруте `product.print`;
- заголовок `X-Robots-Tag: noindex, nofollow` на самом PDF;
- `Disallow: /*/print` в генераторе `robots.txt` (`php artisan sitemap:generate`);
- `rel="nofollow"` на обеих ссылках в карточке товара.

Кэш инвалидируется сам: ключ считается от товара, его значений и опций
атрибутов, реквизитов из настроек и содержимого шаблона оферты. На товар
хранится один файл, прежние версии удаляются при пересборке.
