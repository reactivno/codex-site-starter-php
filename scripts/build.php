<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/seo.php';

// Сборка статики для стенда: GitHub Pages не исполняет PHP, поэтому страницы
// рендерятся здесь и кладутся в _site как готовые файлы.
//
// Данные должны быть собраны тем же --base и --site-url: крошки и canonical
// запекаются в JSON на шаге split-data.php.

const INDEX_FILE = ROOT_DIR . '/data/index.json';
const DEFAULT_OUT = '_site';
const SAMPLE_PER_TYPE = 2;

const STATIC_DIRS = ['css', 'js'];

// Разделы, которые на стенде закрыты от индексации: стенд — копия прода.
const STAND_ROBOTS = "User-agent: *\nDisallow: /\n";

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

$outDir = ROOT_DIR . '/' . ($options['out'] ?? DEFAULT_OUT);
$base = site_base($options['base'] ?? null);
$siteUrl = site_url($options['site-url'] ?? null);
$changedFrom = (string) ($options['changed-from'] ?? '');
$sample = isset($options['sample']);
$samplePerType = $sample ? max(1, (int) $options['sample']) : 0;

// render_values() читает окружение: прокидываем туда то же, что пришло аргументами,
// иначе ссылки в HTML разойдутся с --base.
putenv('SITE_BASE=' . $base);
putenv('SITE_URL=' . $siteUrl);

function say(string $message): void
{
    echo $message . "\n";
}

function remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $dir . '/' . $entry;
        if (is_dir($full)) {
            remove_tree($full);
            @rmdir($full);
            continue;
        }
        @unlink($full);
    }
}

function copy_tree(string $from, string $to): void
{
    if (!is_dir($to) && !@mkdir($to, 0755, true) && !is_dir($to)) {
        fwrite(STDERR, 'не удалось создать ' . $to . "\n");
        exit(1);
    }

    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $source = $from . '/' . $entry;
        $target = $to . '/' . $entry;
        if (is_dir($source)) {
            copy_tree($source, $target);
            continue;
        }
        if (!@copy($source, $target)) {
            fwrite(STDERR, 'не удалось скопировать ' . $source . "\n");
            exit(1);
        }
    }
}

function write_out(string $file, string $content): void
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        fwrite(STDERR, 'не удалось создать ' . $dir . "\n");
        exit(1);
    }
    if (@file_put_contents($file, $content) === false) {
        fwrite(STDERR, 'не удалось записать ' . $file . "\n");
        exit(1);
    }
}

/** Путь страницы -> файл стенда: /uslugi -> uslugi/index.html, home -> index.html. */
function out_file(string $outDir, string $path): string
{
    return $path === HOME_PATH ? $outDir . '/index.html' : $outDir . '/' . $path . '/index.html';
}

/** Строки CSV как карта "path => строка": так видно и добавленные, и изменённые записи. */
function csv_rows(string $csv): array
{
    $rows = [];
    $lines = preg_split('/\r\n|\n/', $csv) ?: [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, 'path')) {
            continue;
        }
        $first = str_getcsv($trimmed, ',', '"', '');
        $path = trim((string) ($first[0] ?? ''));
        if ($path !== '') {
            $rows[$path] = $trimmed;
        }
    }

    return $rows;
}

/**
 * Страницы, изменённые в PR: сравниваем pages.csv из базовой ветки с текущим.
 * Если база недоступна — не падаем, а собираем только образцы по типам.
 */
function changed_paths(string $ref): ?array
{
    // exec(), а не shell_exec(): нужен код возврата, иначе на Windows
    // отсутствие git выглядит как «изменений нет», а не как ошибка.
    $lines = [];
    $code = 1;
    @exec('git show ' . escapeshellarg($ref . ':data/pages.csv'), $lines, $code);

    $current = @file_get_contents(ROOT_DIR . '/data/pages.csv');
    if ($code !== 0 || $lines === [] || $current === false) {
        return null;
    }

    $before = csv_rows(implode("\n", $lines));
    $after = csv_rows($current);

    $changed = [];
    foreach ($after as $path => $line) {
        if (!isset($before[$path]) || $before[$path] !== $line) {
            $changed[] = $path;
        }
    }

    return $changed;
}

$indexRaw = @file_get_contents(INDEX_FILE);
$index = $indexRaw === false ? null : json_decode($indexRaw, true);
if (!is_array($index) || !isset($index['pages'])) {
    fwrite(STDERR, 'нет ' . INDEX_FILE . ": сначала запустите scripts/split-data.php\n");
    exit(1);
}

$entries = [];
foreach ($index['pages'] as $entry) {
    $entries[(string) $entry['path']] = $entry;
}
ksort($entries);

// canonical запекается на шаге split-data. Если данные собраны с другим
// --site-url, страница уедет на стенд с localhost в canonical — лучше упасть сразу.
$firstPath = (string) array_key_first($entries);
$firstJson = $firstPath === '' ? false : @file_get_contents(data_file($firstPath));
$firstPage = $firstJson === false ? null : json_decode($firstJson, true);
$canonical = is_array($firstPage) ? (string) ($firstPage['_canonical'] ?? '') : '';
if ($canonical !== '' && !str_starts_with($canonical, $siteUrl)) {
    fwrite(STDERR, 'данные собраны с адресом "' . $canonical . '", а сборка ждёт "' . $siteUrl
        . "\": перезапустите scripts/split-data.php с тем же --site-url\n");
    exit(1);
}

$selected = [];
if (!$sample && $changedFrom === '') {
    $selected = array_keys($entries); // полная сборка
} else {
    // Образцы: главная плюс по несколько страниц каждого типа.
    $selected[HOME_PATH] = HOME_PATH;
    if (isset($entries[HOME_PATH])) {
        $selected[HOME_PATH] = HOME_PATH;
    }
    $perType = [];
    foreach ($entries as $path => $entry) {
        if ($path === HOME_PATH) {
            continue; // главная уже в выборке, слот типа она не занимает
        }
        $type = (string) $entry['type'];
        $perType[$type] = ($perType[$type] ?? 0) + 1;
        if ($perType[$type] <= $samplePerType && $samplePerType > 0) {
            $selected[$path] = $path;
        }
    }

    if ($changedFrom !== '') {
        $changed = changed_paths($changedFrom);
        if ($changed === null) {
            say('предупреждение: не удалось прочитать data/pages.csv из ' . $changedFrom
                . ' — собираю только образцы');
        } else {
            foreach ($changed as $path) {
                if (isset($entries[$path])) {
                    $selected[$path] = $path;
                }
            }
            say('изменённых страниц: ' . count($changed));
        }
    }
}

remove_tree($outDir);
if (!@mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fwrite(STDERR, 'не удалось создать ' . $outDir . "\n");
    exit(1);
}

$rendered = 0;
$failed = 0;
foreach (array_keys($selected) as $path) {
    $json = @file_get_contents(data_file($path));
    $page = $json === false ? null : json_decode($json, true);
    if (!is_array($page)) {
        fwrite(STDERR, 'нет данных страницы ' . $path . "\n");
        $failed++;
        continue;
    }

    try {
        $html = render((string) $page['type'], $page);
    } catch (Throwable $e) {
        fwrite(STDERR, $path . ': ' . $e->getMessage() . "\n");
        $failed++;
        continue;
    }

    write_out(out_file($outDir, $path), $html);
    $rendered++;
}

try {
    write_out($outDir . '/404.html', render('404', [
        'path' => '',
        'type' => '404',
        'title' => 'Страница не найдена',
        'h1' => 'Страница не найдена',
        'noindex' => 1,
    ]));
} catch (Throwable $e) {
    fwrite(STDERR, '404: ' . $e->getMessage() . "\n");
    $failed++;
}

write_out($outDir . '/sitemap.xml', sitemap_xml(array_map(
    static fn (array $entry): array => $entry + ['noindex' => $entry['noindex'] ?? 0],
    $entries
), $siteUrl));
write_out($outDir . '/robots.txt', STAND_ROBOTS);

foreach (STATIC_DIRS as $dir) {
    copy_tree(ROOT_DIR . '/' . $dir, $outDir . '/' . $dir);
}

say('собрано: ' . $rendered . ', ошибок: ' . $failed);
exit($failed > 0 ? 1 : 0);
