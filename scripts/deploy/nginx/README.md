# nginx: защита генератора PDF-оферты

Тот же приём, что уже стоит на kratonshop (`/etc/nginx/conf.d/kratonshop-print-bots.conf`
плюс `location` в вхосте).

## Что ставить

1. Скопировать карту ботов в `conf.d` (грузится до `sites-enabled`):

   ```
   scp scripts/deploy/nginx/intertooler-print-bots.conf \
       intertooler-production:/tmp/intertooler-print-bots.conf
   ssh intertooler-production \
       'sudo install -m 644 /tmp/intertooler-print-bots.conf /etc/nginx/conf.d/'
   ```

2. Добавить в `/etc/nginx/sites-available/intertooler.ru`, в блок
   `server { server_name intertooler.ru; ... }`, **выше** `location / {`:

   ```nginx
   # Тяжёлый генератор PDF — известным краулерам сюда нельзя.
   location ~ ^/product/[^/]+/print$ {
       if ($intertooler_bad_print_bot) {
           return 403;
       }

       try_files $uri $uri/ /index.php?$query_string;
   }
   ```

3. Проверить и применить:

   ```
   ssh intertooler-production 'sudo nginx -t && sudo systemctl reload nginx'
   ```

## Проверка

```
curl -s -o /dev/null -w '%{http_code}\n' -A 'Bytespider' \
     https://intertooler.ru/product/<slug>/print          # ждём 403
curl -s -o /dev/null -w '%{http_code}\n' \
     https://intertooler.ru/product/<slug>/print          # ждём 200
```

## Что закрыто на стороне приложения

- `throttle:12,1` на маршруте `product.print` — против краулеров, которых нет в карте;
- заголовок `X-Robots-Tag: noindex, nofollow` на самом PDF;
- `Disallow: /*/print` в генераторе `robots.txt` (`php artisan sitemap:generate`);
- `rel="nofollow"` на обеих ссылках в карточке товара.
