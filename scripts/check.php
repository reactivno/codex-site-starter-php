<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/config.php';

// Проверка собранной статики: страница без title, h1 или абсолютного canonical
// не должна доехать до стенда. Без этого битые метаданные ловятся глазами уже
// после того, как ссылку отдали заказчику.

// У 404 нет canonical намеренно: страницы не существует.
const SKIP_FILES = ['404.html'];

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Только из командной строки\n";
    exit;
}

$options = [];
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', (string) $argument, $match) === 1) {
        $options[$match[1]] = $match[2] ?? '1';
    }
}

$dir = ROOT_DIR . '/' . ($options['dir'] ?? '_site');
if (!is_dir($dir)) {
    fwrite(STDERR, 'нет каталога ' . $dir . "\n");
    exit(1);
}

function collect_html(string $dir, array &$files): void
{
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $dir . '/' . $entry;
        if (is_dir($full)) {
            collect_html($full, $files);
            continue;
        }
        if (str_ends_with($entry, '.html')) {
            $files[] = $full;
        }
    }
}

$files = [];
collect_html($dir, $files);
sort($files);

if ($files === []) {
    fwrite(STDERR, 'в ' . $dir . ' нет html — сборка пустая' . "\n");
    exit(1);
}

$problems = [];
$checked = 0;

foreach ($files as $file) {
    $name = substr($file, strlen($dir) + 1);
    if (in_array($name, SKIP_FILES, true)) {
        continue;
    }

    $html = @file_get_contents($file);
    if ($html === false) {
        $problems[] = $name . ': файл не читается';
        continue;
    }

    $checked++;

    if (preg_match('#<title>(.*?)</title>#is', $html, $match) !== 1 || trim($match[1]) === '') {
        $problems[] = $name . ': нет <title>';
    }
    if (preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $match) !== 1
        || trim(strip_tags($match[1])) === '') {
        $problems[] = $name . ': нет <h1>';
    }
    // Схема зависит от SITE_URL: http — временный режим, пока у хостинга нет TLS.
    if (preg_match('#<link rel="canonical" href="(https?://[^"]+)"#i', $html, $match) !== 1) {
        $problems[] = $name . ': нет абсолютного <link rel="canonical" href="http(s)://...">';
    }
}

if ($problems !== []) {
    foreach ($problems as $problem) {
        fwrite(STDERR, $problem . "\n");
    }
    fwrite(STDERR, 'проверено: ' . $checked . ', проблем: ' . count($problems) . "\n");
    exit(1);
}

echo 'проверено: ' . $checked . ", проблем нет\n";
