# Timeweb S3 + CDN (`img.sancan.ru`)

## Env (API `/var/www/sancan/pixer-api/.env`)

```env
FILESYSTEM_DISK=s3
MEDIA_DISK=s3

AWS_ACCESS_KEY_ID=          # вставить вручную
AWS_SECRET_ACCESS_KEY=      # вставить вручную
AWS_DEFAULT_REGION=ru-1
AWS_BUCKET=9fba268e-1b35-4def-9dfe-77db6d47e612
AWS_ENDPOINT=https://s3.twcstorage.ru
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_URL=https://nqx1cwsokx.cdn.twcstorage.ru

MEDIA_URL=https://nqx1cwsokx.cdn.twcstorage.ru
ASSETS_BASE_URL=https://nqx1cwsokx.cdn.twcstorage.ru
```

Текущий CDN-домен Timeweb: `nqx1cwsokx.cdn.twcstorage.ru`  
(опционально позже: персональный `img.sancan.ru` → CNAME на этот CDN)


После правок:

```bash
cd /var/www/sancan/pixer-api
php artisan config:clear
php artisan cache:clear
php artisan s3:check
```

## Frontend

Shop / Admin:

```env
NEXT_PUBLIC_ASSETS_BASE_URL=https://img.sancan.ru
```

В `next.config.js` уже добавлен host `img.sancan.ru`.

## Ручные шаги CDN в Timeweb

1. Открыть раздел **CDN** в панели Timeweb.
2. Создать CDN-ресурс.
3. Источник: новый S3-бакет `9fba268e-1b35-4def-9dfe-77db6d47e612`.
4. Добавить персональный домен `img.sancan.ru`.
5. Скопировать DNS-запись **из панели Timeweb** (CNAME/A — не выдумывать).
6. Добавить запись в DNS домена `sancan.ru`.
7. Дождаться выпуска SSL для CDN-домена.
8. Проверить тестовый объект:  
   `https://img.sancan.ru/<key-тестового-файла>`  
   (после `php artisan s3:check` можно загрузить вручную картинку и открыть по ключу).

## Аудит старых файлов

Старый бакет удалён — объекты по старым URL отсутствуют.

```bash
php artisan media:audit --limit=100
php artisan media:replace-old-base-url --dry-run
```

`--dry-run` только показывает, сколько записей со старым base URL.  
Реальную замену URL в БД **не запускать** без подтверждения (файлов в новом бакете всё равно нет).

## Команды

| Команда | Назначение |
|---------|------------|
| `php artisan s3:check` | upload/read/delete тестового файла |
| `php artisan media:audit` | сколько объектов есть/нет в новом S3 |
| `php artisan media:replace-old-base-url --dry-run` | отчёт по замене base URL |
