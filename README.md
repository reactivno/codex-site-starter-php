# Базовый проект сайта

Каркас сайта на HTML/CSS/JS с формой, которая уходит в PHP и отправляется письмом через `mail()`.
Страницы не лежат отдельными файлами: они рендерятся из `data/pages.csv` по шаблонам и кэшируются.
Зависимостей нет: ни npm, ни composer. Нужен только PHP 8.1+.

## Запуск локально

```bash
php scripts/split-data.php      # CSV -> data/pages/*.json, data/index.json, sitemap.xml
php -S localhost:8000 index.php
```

Сайт — <http://localhost:8000>. Второй аргумент обязателен: встроенный сервер не читает `.htaccess`,
и роутер `index.php` обрабатывает адреса сам. Форма отправит POST в `php/send.php`.

Переменные для локальной проверки: скопируйте `.env.example` в `.env` и заполните.
Встроенный сервер PHP не читает `.env` сам — для локальной отправки задайте переменные в окружении:

```bash
MAIL_TO=you@example.com php -S localhost:8000 index.php
```

Без `MAIL_TO` обработчик вернёт 500 «Получатель не настроен» — это ожидаемо.

## Структура

```text
data/pages.csv        источник правды: страницы, их тексты и метаданные
data/pages/           собирается из CSV, в git не хранится
data/index.json       лёгкий индекс, собирается из CSV
index.php             роутер: URL -> данные -> кэш или рендер
lib/config.php        пути, адрес сайта, лимиты
lib/render.php        шаблон + данные -> HTML
lib/cache.php         атомарная запись кэша
lib/seo.php           canonical, крошки, JSON-LD, sitemap, robots
templates/            page.html, category.html, 404.html
partials/             header, footer, breadcrumbs, form
scripts/split-data.php  CSV -> JSON + валидация + sitemap + robots
scripts/warmup.php      прогрев и обновление кэша
scripts/build.php       статика для стенда (выборка, с --base)
scripts/check.php       проверка title/h1/canonical в собранном _site
cache/                готовый HTML, в git не хранится
css/, js/, php/       стили, скрипты, обработчик формы
.github/workflows/    preview | cleanup | deploy
AGENTS.md             правила работы Codex
TZ.md                 требования, колонки CSV, правила SEO
```

Корень репозитория = корень сайта. Служебные файлы (`.github`, `.git`, `.env`, `*.md`, `scripts/`)
на хостинг не загружаются, кроме `scripts/warmup.php` — его вызывает деплой.

## Как добавить страницу

1. Допишите строку в `data/pages.csv`. Обязательные колонки: `path`, `type`, `title`, `description`,
   `h1`, `lastmod`. Полное описание колонок — в `TZ.md` §4.
2. `path` — это адрес: `uslugi/remont-kvartir` даст `/uslugi/remont-kvartir`. У каждого раздела
   должна быть своя строка (`uslugi`), иначе сборка упадёт с подсказкой, какой строки не хватает.
3. Выполните `php scripts/split-data.php`. Скрипт соберёт JSON и `sitemap.xml` и скажет, если что-то
   не так: пустое поле, дубль `path` или `title`, битая дата, неизвестный `type` — с номером строки.
4. Откройте страницу локально и проверьте `canonical`, `title`, `h1`.

Страницы одного типа используют один шаблон из `templates/`. Нужен новый вид страницы — добавьте
`templates/<type>.html` и укажите этот `type` в CSV.

## Как обновить страницы после правки шаблона

Apache отдаёт готовый HTML из `cache/` напрямую, PHP при этом не вызывается. Поэтому после правки
`templates/` или `partials/` кэш нужно перерисовать:

```bash
php scripts/warmup.php                       # все страницы
php scripts/warmup.php --from=0 --limit=500  # батчами
php scripts/warmup.php --prune               # убрать из кэша удалённые страницы
```

`--prune` удаляет из `cache/` файлы, которых нет в `data/index.json` (и зависшие `tmp-*`): без него
страница, убранная из CSV, продолжает отдаваться с кодом `200`, потому что Apache берёт файл из кэша.
Если индекс не читается или пуст, `prune` ничего не трогает и падает с кодом 1.

На проде это делает `deploy.yml` сразу после заливки по FTP: сначала прогрев батчами по 500 (сколько
страниц — берётся из `data/index.json`), потом один вызов `prune`. Токен передаётся заголовком
`X-Warmup-Token` — в query он попал бы в логи сервера. Шаг падает, если в ответе есть `ошибок: N`
при `N > 0`, и если секрет не задан.

Проверки руками:

```bash
# прогреваем одну страницу батчем и смотрим ответ
curl -s -H "X-Warmup-Token: $WARMUP_TOKEN" \
  "https://example.ru/scripts/warmup.php?from=0&limit=500"
# чистим кэш от удалённых страниц
curl -s -H "X-Warmup-Token: $WARMUP_TOKEN" \
  "https://example.ru/scripts/warmup.php?prune=1"
```

Без заголовка или с чужим токеном скрипт отвечает `403`.

## Адрес сайта и домен

Домена в коде нет: это стартер. Адрес берётся по цепочке `--site-url=...` (аргумент скрипта) →
переменная окружения `SITE_URL` → `http://localhost:8000`. Домен попадает в `canonical`, `sitemap.xml`
и `robots.txt`.

Подключили домен — задайте `SITE_URL` в переменных окружения сайта и в переменной репозитория
`SITE_URL` (Settings → Variables → Actions), затем пересоберите данные и прогрейте кэш.
**Для деплоя `SITE_URL` обязателен:** `deploy.yml` падает с сообщением, если переменная пуста или
без `http(s)://`, — иначе на прод уехали бы `canonical` и `sitemap` с `localhost`.

### Схема: https или http

Схема берётся из `SITE_URL` и определяет только одно — включён ли редирект `http → https`:

| `SITE_URL` | Что делает `deploy.yml` | `canonical`, `sitemap` |
| --- | --- | --- |
| `https://домен` | редирект включён (маркера нет) | `https://…` |
| `http://домен` | создаёт `http-only.txt`, редирект выключен | `http://…` |

`.htaccess` смотрит на наличие `http-only.txt` в корне, поэтому переключение — это одна строка в
переменных и повторный деплой, без правок кода. Файл-маркер наружу не отдаётся и в git не попадает.

`http` — временный режим, пока у хостинга нет TLS: форма и тикеты идут открытым текстом, а при переходе
на `https` адреса в `canonical` и `sitemap` сменятся, и старые `http`-страницы надо будет закрыть `301`
(это и включит редирект). По умолчанию держите `https`.

В `.htaccess` уже есть редирект `http → https` (кроме `localhost`, с учётом `X-Forwarded-Proto`) —
он работает, когда `SITE_URL` начинается с `https://`. Редиректа `www → без www` там нет намеренно:
домен не задан, а правило по `%{HTTP_HOST}` — это открытый редирект на подставленный Host.
Когда домен появится, добавьте в блок `mod_rewrite` (подставив свой домен):

```apache
RewriteCond %{HTTP_HOST} ^(?:www\.)?ВАШ-ДОМЕН.ru$ [NC]
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://ВАШ-ДОМЕН.ru%{REQUEST_URI} [R=301,L]
RewriteCond %{HTTP_HOST} ^www\.ВАШ-ДОМЕН\.ru$ [NC]
RewriteRule ^ https://ВАШ-ДОМЕН.ru%{REQUEST_URI} [R=301,L]
```

## Проверка `.htaccess` на Apache

Встроенный сервер PHP (`php -S`) не читает `.htaccess`, поэтому кэш и редиректы проверяются только на
Apache (`mod_rewrite`, `AllowOverride All`, `mod_php`). Каждый адрес запрашивается дважды: первый запрос
рендерит страницу и создаёт `cache/`, второй должен отдаваться файлом кэша.

```bash
curl -sI https://example.ru/uslugi                     # 200, кэша ещё нет
curl -sI https://example.ru/uslugi | grep -iE 'etag|last-modified'   # 200 + ETag и Last-Modified
curl -sI https://example.ru/cache/uslugi.html          # 403
curl -sI https://example.ru/.ftp-deploy-sync-state.json  # 403 (dotfiles)
curl -sI https://example.ru/uslugi/                    # 301 на /uslugi
```

Если второй ответ отдаёт `403` — значит после внутреннего редиректа переменная окружения стала
`REDIRECT_CACHE_HIT`, и условие запрета надо смотреть по ней, а не по `CACHE_HIT`.

## Секреты GitHub (Settings → Secrets)

| Секрет | Значение |
| --- | --- |
| `FTP_SERVER` | хост FTP, например `ftp.example.com` (только для прода) |
| `FTP_USERNAME` | логин FTP (только для прода) |
| `FTP_PASSWORD` | пароль FTP (только для прода) |
| `WARMUP_TOKEN` | произвольная случайная строка; то же значение — в окружении сайта. Нужен для прогрева, без него шаг деплоя падает |

`WARMUP_TOKEN` генерируется один раз: `openssl rand -hex 32`. Ни с чем не связан, нужен только чтобы
посторонний не мог перерисовать кэш. Подставьте то же значение в переменные окружения сайта.

## GitHub Pages (для стендов)

1. Репозиторий публичный, либо платный план — на бесплатном страницы приватного репозитория не публикуются.
2. Settings → Pages → Source: **Deploy from a branch**, ветка `gh-pages`, папка `/(root)`.
3. Ветка `gh-pages` появится после первого PR: её создаёт workflow `Preview`.
4. Стенд PR живёт по адресу `https://<owner>.github.io/<repo>/pr-<номер>/`.

PHP на Pages не выполняется, поэтому стенд собирает `scripts/build.php`: в `gh-pages` едут только
готовые `html` и `css/`, `js/`. Форма на стенде отключена в `js/main.js`, `robots.txt` стенда
закрывает его целиком.

## Переменные окружения сайта (на хостинге)

| Переменная | Значение |
| --- | --- |
| `MAIL_TO` | кому приходят заявки |
| `MAIL_FROM` | отправитель в письме; по умолчанию `no-reply@домен сайта` |
| `MAIL_SUBJECT_PREFIX` | приписка к теме, например название сайта |
| `SITE_URL` | адрес сайта, например `https://example.ru` — для canonical и sitemap |
| `SITE_BASE` | префикс путей; на проде пусто, на стенде его подставляет workflow |
| `WARMUP_TOKEN` | токен прогрева кэша, тот же, что в GitHub Secrets |

Как задать их на FastVPS — в панели сайта. `getenv()` видит только то, что реально попадает в
окружение PHP-FPM; если пусто, проверьте настройки панели.

## Другая форма

Обработчик не знает заранее, какие поля придут. Под чужую форму правится только массив вверху `php/send.php`:

```php
const FORM_CONFIG = [
    'required' => ['phone', 'consent'],
    'email' => 'email',     // поля email в форме нет — проверка не срабатывает
    'honeypot' => 'website',
    'max_length' => 5000,
    'max_body' => 65536,
];
```

- `required` — без этих полей заявка не принимается. По умолчанию `phone` и `consent`.
- `email` — имя поля с email; если такого поля нет, проверка не срабатывает. Пустая строка отключает её совсем.
- `honeypot` — скрытое поле; если заполнено, письмо не шлётся.

Всё остальное, что пришло, переносится в письмо строками `поле: значение`.
Сама разметка формы лежит в `partials/form.html` — она общая для всех шаблонов.

## Как идёт работа

1. Codex делает ветку, правки, PR.
2. Workflow `Preview` собирает данные и выборку страниц (`build.php`), проверяет метаданные
   (`check.php`) и публикует стенд в ветку `gh-pages` (папка `pr-<номер>`), ссылку пишет в PR.
3. Вы смотрите стенд и одобряете PR.
4. После merge в главную ветку workflow `Deploy` собирает данные и заливает сайт по FTP, затем
   прогревает кэш по токену.
5. `Cleanup` удаляет папку стенда из `gh-pages` при закрытии PR.
