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
$prune = false;

if ($isCli) {
    foreach (array_slice($argv ?? [], 1) as $argument) {
        if ((string) $argument === '--prune') {
            $prune = true;
        } elseif (preg_match('/^--(from|limit)=(\d+)$/', (string) $argument, $match) === 1) {
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
    $prune = (($_GET['prune'] ?? '') === '1');
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

if ($prune) {
    // Режим prune: из cache/ удаляются все *.html, которых нет в data/index.json,
    // плюс зависшие tmp-* (остатки прерванной атомарной записи) и пустые подкаталоги.
    // Если индекс пуст (count=0) — ничего не удаляем: иначе одна битая/пустая
    // сборка снесла бы весь кэш. (Нечитаемый индекс уже выше => exit(1).)
    $indexCount = (int) ($index['count'] ?? 0);
    if ($indexCount === 0 || empty($paths)) {
        if (!$isCli) {
            http_response_code(500);
        }
        echo 'prune: индекс пуст (count=0) — кэш не трогаем' . "\n";
        exit(1);
    }

    // Ожидаемые файлы кэша: cache/<path>.html для каждой страницы индекса.
    // Собираем через cache_file(), чтобы ключ совпадал с путём записи 1-в-1.
    $expected = [];
    foreach ($paths as $p) {
        $expected[cache_file($p)] = true;
    }

    $removed = 0;
    $remaining = 0;
    $subDirs = [];
    $stack = [CACHE_DIR];
    while ($stack) {
        $dir = array_pop($stack);
        $entries = @scandir($dir);
        if ($entries === false) {
            continue;
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            // '/' (а не DIRECTORY_SEPARATOR): чтобы $full совпадал с key из cache_file().
            $full = $dir . '/' . $name;
            if (is_dir($full)) {
                $stack[] = $full;
                if ($full !== CACHE_DIR) {
                    $subDirs[] = $full;
                }
                continue;
            }
            // tmp-* — остатки атомарной записи, удаляем всегда.
            if (str_starts_with($name, 'tmp-')) {
                if (@unlink($full)) {
                    $removed++;
                }
                continue;
            }
            // *.html вне индекса — страница уже удалена из CSV.
            if (str_ends_with($name, '.html') && !isset($expected[$full])) {
                if (@unlink($full)) {
                    $removed++;
                }
                continue;
            }
            $remaining++;
        }
    }

    // Пустые подкаталоги: снизу вверх, чтобы очистился и родительский каталог.
    usort($subDirs, static fn (string $a, string $b): int => substr_count($b, '/') - substr_count($a, '/'));
    foreach ($subDirs as $d) {
        @rmdir($d);
    }

    echo 'удалено: ' . $removed . ', осталось: ' . $remaining . "\n";
    exit(0);
}

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
