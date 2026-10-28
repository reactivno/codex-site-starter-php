<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/cache.php';

// Прогрев кэша: Apache отдаёт cache/<path>.html напрямую, PHP при этом не
// вызывается. Значит без прогрева правка шаблона не долетает до посетителя
// никогда — старый файл живёт, пока его не перезапишут.
//
// Два режима: CLI (локально, в CI) и HTTP с токеном: SSH и cron на хостинге нет,
// а деплой умеет только FTP.

const INDEX_FILE = ROOT_DIR . '/data/index.json';
const DEFAULT_BATCH = 500;

$isCli = PHP_SAPI === 'cli';
$from = 0;
$limit = DEFAULT_BATCH;

if ($isCli) {
    foreach (array_slice($argv ?? [], 1) as $argument) {
        if (preg_match('/^--(from|limit)=(\d+)$/', (string) $argument, $match) === 1) {
            ${$match[1]} = (int) $match[2];
        }
    }
} else {
    // Вызов по HTTP — только с токеном: WARMUP_TOKEN есть и в GitHub Secrets,
    // и в переменных окружения сайта. Пустая переменная полностью выключает режим.
    header('X-Robots-Tag: noindex');
    header('Content-Type: text/plain; charset=utf-8');

    $expected = env_value('WARMUP_TOKEN');
    $given = (string) ($_GET['token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        echo "403 Forbidden\n";
        exit;
    }

    $from = max(0, (int) ($_GET['from'] ?? 0));
    $limit = (int) ($_GET['limit'] ?? DEFAULT_BATCH);
}

$indexRaw = @file_get_contents(INDEX_FILE);
$index = $indexRaw === false ? null : json_decode($indexRaw, true);
if (!is_array($index) || !isset($index['pages']) || !is_array($index['pages'])) {
    fwrite(STDERR, 'нет ' . INDEX_FILE . ": сначала запустите scripts/split-data.php\n");
    http_response_code($isCli ? 0 : 500);
    echo "индекс не собран\n";
    exit(1);
}

$paths = [];
foreach ($index['pages'] as $entry) {
    if (isset($entry['path'])) {
        $paths[] = (string) $entry['path'];
    }
}
sort($paths);

$batch = array_slice($paths, max(0, $from), $limit > 0 ? $limit : null);

$done = 0;
$failed = 0;
foreach ($batch as $path) {
    $json = @file_get_contents(data_file($path));
    $page = $json === false ? null : json_decode($json, true);
    if (!is_array($page)) {
        error_log('warmup: нет данных страницы ' . $path);
        $failed++;
        continue;
    }

    try {
        $html = render((string) ($page['type'] ?? ''), $page);
    } catch (Throwable $e) {
        // Одна битая страница не должна останавливать прогрев остальных.
        error_log('warmup: ' . $path . ': ' . $e->getMessage());
        $failed++;
        continue;
    }

    if (!cache_store($path, $html)) {
        error_log('warmup: не удалось записать ' . cache_file($path));
        $failed++;
        continue;
    }

    $done++;
}

echo 'готово: ' . $done . ', ошибок: ' . $failed . "\n";
exit($failed > 0 ? 1 : 0);
