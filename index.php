<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/cache.php';
require_once __DIR__ . '/lib/render.php';

// Роутер: URL -> path -> data/pages/<path>.json -> кэш или рендер.
// На проде готовый HTML отдаёт Apache (rewrite на cache/<path>.html), сюда запрос
// попадает только при промахе кэша. На php -S роутер обслуживает всё сам, потому
// что встроенный сервер не читает .htaccess.

const NOT_FOUND_TYPE = '404';

// Единственное исключение из запрета scripts/: деплой дёргает прогрев по HTTP,
// потому что SSH и cron на хостинге нет. Доступ закрыт токеном внутри файла.
const WARMUP_ENTRY = 'scripts/warmup.php';

const NOT_FOUND_PAGE = [
    'path' => '',
    'type' => NOT_FOUND_TYPE,
    'title' => 'Страница не найдена',
    'description' => '',
    'h1' => 'Страница не найдена',
    'noindex' => 1,
];

/** Ошибка рендера: 503 и запись в лог. Пустая страница с кодом 200 хуже ошибки. */
function fail(string $reason): never
{
    error_log('render: ' . $reason);
    http_response_code(503);
    header('Retry-After: 60');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Страница временно недоступна\n";
    exit;
}

function not_found(): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');

    try {
        echo render(NOT_FOUND_TYPE, NOT_FOUND_PAGE);
    } catch (Throwable $e) {
        error_log('404: ' . $e->getMessage());
        echo '<!doctype html><meta charset="utf-8"><title>404</title><h1>Страница не найдена</h1>';
    }

    exit;
}

/** Одно и то же предупреждение не должно попадать в лог на каждый запрос. */
function log_once(string $message): void
{
    static $logged = false;

    if (!$logged) {
        error_log($message);
        $logged = true;
    }
}

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = rawurldecode((string) (parse_url($requestUri, PHP_URL_PATH) ?? '/'));

// Префикс стенда снимаем до всего остального: иначе и статика, и запреты
// ищутся по адресу с /репозиторий/pr-N/ в начале.
$base = site_base();
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}

if ($path === '') {
    $path = '/';
}

// Статика и запреты: воспроизводим поведение .htaccess руками.
if (PHP_SAPI === 'cli-server') {
    $relative = ltrim($path, '/');
    $isWarmupEntry = $relative === WARMUP_ENTRY;

    foreach (DENY_PREFIXES as $prefix) {
        if ($isWarmupEntry) {
            break;
        }
        if ($relative === $prefix || str_starts_with($relative, $prefix . '/')) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "403 Forbidden\n";
            return;
        }
    }

    if ($relative !== '' && is_file(__DIR__ . '/' . $relative)) {
        return false; // пусть встроенный сервер отдаст файл сам
    }
}

$path = trim($path, '/');
if ($path === '' || $path === 'index.html') {
    $path = HOME_PATH;
}

if (strlen($path) > PATH_MAX_LENGTH || preg_match(PATH_PATTERN, $path) !== 1) {
    not_found();
}

$json = @file_get_contents(data_file($path));
if ($json === false) {
    // Страница, удалённая из CSV, должна давать 404, а не 500.
    not_found();
}

$page = json_decode($json, true);
if (!is_array($page)) {
    fail('битый JSON: ' . data_file($path));
}

$cached = cache_file($path);
if (is_file($cached)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($cached);

    return;
}

try {
    $html = render((string) ($page['type'] ?? ''), $page);
} catch (Throwable $e) {
    fail($e->getMessage());
}

if (!cache_store($path, $html)) {
    // Сайт работает и без кэша: страница дороже, но не сломана.
    log_once('кэш недоступен, запись в ' . CACHE_DIR . ' не удалась');
}

header('Content-Type: text/html; charset=utf-8');
echo $html;
