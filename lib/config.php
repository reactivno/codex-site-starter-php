<?php

declare(strict_types=1);

// Единая точка правды по путям и адресам.
// Домена здесь нет намеренно: репозиторий — стартер, куда его развернут, заранее
// неизвестно. Адрес приходит из аргумента CLI или переменной окружения хостинга,
// иначе остаётся localhost: это признак локального запуска, а не ошибка.

const ROOT_DIR = __DIR__ . '/..';
const DATA_DIR = ROOT_DIR . '/data/pages';
const CACHE_DIR = ROOT_DIR . '/cache';
const TPL_DIR = ROOT_DIR . '/templates';
const PARTIAL_DIR = ROOT_DIR . '/partials';

// Главная страница живёт в строке CSV с path=home и в URL "/".
const HOME_PATH = 'home';

// Служебные каталоги: наружу не отдаются. Apache закрывает их rewrite-ом,
// роутер — этим списком, чтобы запрет работал и на php -S, где .htaccess не читается.
const DENY_PREFIXES = ['cache', 'data', 'lib', 'scripts', 'templates', 'partials'];

// Имена в корне сайта: страница не может занять такой адрес.
const RESERVED_SEGMENTS = [
    'cache', 'data', 'lib', 'scripts', 'templates', 'partials',
    'css', 'js', 'php', 'sitemap', 'robots', 'index',
];

// Один сегмент или несколько через слэш, до 4 сегментов пути.
const PATH_PATTERN = '#^[a-z0-9-]+(?:/[a-z0-9-]+){0,3}$#D';
const PATH_MAX_LENGTH = 200;

/**
 * Значение из окружения: getenv(), затем $_ENV, затем $_SERVER.
 * Порядок тот же, что в php/send.php: на хостингах переменные видны по-разному.
 */
function env_value(string $key): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';
    }

    return trim((string) $value);
}

/**
 * Адрес сайта без слэша на конце: аргумент CLI, затем SITE_URL, затем localhost.
 * Домен в коде не появляется нигде — это требование стартера.
 */
function site_url(?string $override = null): string
{
    $url = trim((string) ($override ?? env_value('SITE_URL')));
    if ($url === '') {
        $url = 'http://localhost:8000';
    }

    return rtrim($url, '/');
}

/**
 * Префикс ссылок: пусто на проде, /репозиторий/pr-N на стенде GitHub Pages.
 * Без него относительные пути на стенде резолвятся в несуществующие адреса.
 */
function site_base(?string $override = null): string
{
    $base = trim((string) ($override ?? env_value('SITE_BASE')));
    if ($base === '' || $base === '/') {
        return '';
    }

    return '/' . trim($base, '/');
}

/** Путь страницы -> адрес на сайте: главная живёт в корне. */
function page_uri(string $path): string
{
    return $path === HOME_PATH ? '/' : '/' . $path;
}

/** Абсолютный адрес страницы — для canonical, sitemap и JSON-LD. */
function page_url(string $path, ?string $siteUrl = null): string
{
    return rtrim(site_url($siteUrl), '/') . page_uri($path);
}

/** Файл данных одной страницы. Путь перед вызовом уже провалидирован. */
function data_file(string $path): string
{
    return DATA_DIR . '/' . $path . '.json';
}
