<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/seo.php';

// Сборка данных: data/pages.csv -> data/pages/<path>.json + data/index.json.
// Битый CSV должен ломать сборку с адресом ошибки (файл, строка, поле),
// а не выдавать пустые title на живом сайте.

const CSV_FILE = ROOT_DIR . '/data/pages.csv';
const PAGES_DIR = ROOT_DIR . '/data/pages';
const INDEX_FILE = ROOT_DIR . '/data/index.json';
const SITEMAP_FILE = ROOT_DIR . '/sitemap.xml';
const ROBOTS_FILE = ROOT_DIR . '/robots.txt';

const REQUIRED_COLUMNS = ['path', 'type', 'title', 'description', 'h1', 'lastmod'];
const MAX_ERRORS = 50;

// Поля, которые считает код: их нет в CSV, но шаблон вправе их выводить.
const COMPUTED_FIELDS = [
    '_canonical', '_base', '_form_action', '_robots',
    '_breadcrumbs', '_related', '_jsonld',
    '_children', '_neighbors',
];

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Только из командной строки\n";
    exit;
}

// --site-url=https://домен и --base=/репозиторий/pr-N передаются сборкой стенда;
// без них canonical и sitemap указывают на localhost.
$cliOptions = [];
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', (string) $argument, $match) === 1) {
        $cliOptions[$match[1]] = $match[2];
    }
}

// --site-url передан (даже пустым): канонический адрес не должен упасть на localhost.
// Разрешён только абсолютный https-адрес без пути и слэша на конце.
if (array_key_exists('site-url', $cliOptions)) {
    $value = trim((string) $cliOptions['site-url']);
    if (preg_match('#^https://[a-z0-9.-]+$#i', $value) !== 1) {
        fwrite(STDERR, "--site-url должен быть абсолютным https-адресом без пути и слэша: \"$value\"\n");
        exit(1);
    }
}

$siteUrl = site_url($cliOptions['site-url'] ?? null);
$hasSiteUrl = trim((string) ($cliOptions['site-url'] ?? '')) !== '';
$base = site_base($cliOptions['base'] ?? null);

$errors = [];

/** Ошибка с обязательным префиксом файла: видно, что именно читали. */
function add_error(string $message): void
{
    global $errors;

    $errors[] = 'pages.csv: ' . $message;
}

function add_warning(string $message): void
{
    fwrite(STDERR, 'предупреждение: pages.csv: ' . $message . "\n");
}

/** Разделитель определяем по шапке: Excel в русской локали пишет ";" и BOM. */
function detect_delimiter(string $raw): string
{
    $firstLine = strstr($raw, "\n", true);
    if ($firstLine === false) {
        $firstLine = $raw;
    }

    $best = ',';
    $bestCount = 0;
    foreach ([',', ';', "\t"] as $candidate) {
        $count = substr_count($firstLine, $candidate);
        if ($count > $bestCount) {
            $best = $candidate;
            $bestCount = $count;
        }
    }

    return $best;
}

/** Удаление сгенерированного: удалённая из CSV страница не должна отдаваться вечно. */
function clear_dir(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);

        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $full = $dir . '/' . $entry;
        if (is_dir($full)) {
            clear_dir($full);
            @rmdir($full);

            continue;
        }
        @unlink($full);
    }
}

function write_file(string $file, string $content): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (@file_put_contents($file, $content) === false) {
        fwrite(STDERR, 'не удалось записать ' . $file . "\n");
        exit(1);
    }
}

/** Остановка сборки с сообщением и кодом 1: дальше писать файлы бессмысленно. */
function build_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function json_encode_page(array $data): string
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        fwrite(STDERR, 'не удалось собрать JSON: ' . json_last_error_msg() . "\n");
        exit(1);
    }

    return $json . "\n";
}

/**
 * Проверка шаблона против шапки CSV: поле, которого нет в данных, — ошибка сборки.
 * Выполняется один раз на type, а не на каждую строку.
 */
function check_template(string $type, array $columns): array
{
    $file = TPL_DIR . '/' . $type . '.html';
    $prefix = 'templates/' . $type . '.html: ';

    $html = @file_get_contents($file);
    if ($html === false) {
        return [$prefix . 'файл не читается'];
    }

    try {
        $html = expand_partials($html, []);
    } catch (RuntimeException $e) {
        return [$prefix . $e->getMessage()];
    }

    $found = [];

    preg_match_all('/\{\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}\}/i', $html, $rawMatches);
    preg_match_all('/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/i', $html, $plainMatches);
    $used = array_unique([...$rawMatches[1], ...$plainMatches[1]]);

    foreach ($used as $field) {
        if (in_array($field, COMPUTED_FIELDS, true)) {
            continue;
        }
        if (!isset($columns[$field])) {
            $found[] = $prefix . 'поле "' . $field . '" отсутствует в pages.csv';
        }
    }

    foreach (array_unique($rawMatches[1]) as $field) {
        if (!in_array($field, RAW_FIELDS, true)) {
            $found[] = $prefix . 'поле "' . $field . '" нельзя выводить без экранирования';
        }
    }

    return array_values(array_unique($found));
}

/**
 * Готовые элементы «Смотрите также» для каждой страницы, один расчёт на всю сборку.
 * Пустые массивы тоже кладём: страница без соседей — это не «нет ключа».
 */
function related_map(array $byPath, array $childrenOf): array
{
    $map = [];
    foreach (array_keys($byPath) as $path) {
        $map[$path] = related_for($path, $byPath, $childrenOf);
    }

    return $map;
}

/**
 * Входящая ссылка на страницу приходит откуда: родитель-категория (ссылается
 * на всех детей), соседи prev/next, блок «Смотрите также», крошки (предки).
 * Главная исключена: у неё по определению нет входящих, и не нужно.
 * Сирота без единой входящей не проиндексируется — требование ТЗ §5.4.5.
 */
function orphan_paths(array $byPath, array $childrenOf, array $relatedMap, array $ancestorsOf): array
{
    $orphans = [];

    foreach ($byPath as $path => $data) {
        if ($path === HOME_PATH) {
            continue;
        }

        $segments = explode('/', $path);
        $parent = count($segments) > 1 ? implode('/', array_slice($segments, 0, -1)) : '';

        // От родителя-категории: категория выводит полный список детей.
        $hasInbound = isset($childrenOf[$parent]) && (string) ($byPath[$parent]['type'] ?? '') === 'category';

        // От соседей: хотя бы один из prev/next ссылается на эту страницу.
        if (!$hasInbound) {
            $siblings = $childrenOf[$parent] ?? [];
            $position = array_search($path, $siblings, true);
            if ($position !== false) {
                $hasInbound = $position > 0 || $position < count($siblings) - 1;
            }
        }

        // От «Смотрите также»: эта страница попала в related чужой страницы.
        if (!$hasInbound) {
            foreach ($relatedMap[$path] ?? [] as $item) {
                if ($item['path'] === $path) {
                    $hasInbound = true;
                    break;
                }
            }
        }

        // От крошек: предок-категория ссылается на неё как на раздел в крошках.
        if (!$hasInbound) {
            foreach ($ancestorsOf[$path] ?? [] as $ancestor) {
                if ((string) ($byPath[(string) $ancestor['path']]['type'] ?? '') === 'category') {
                    $hasInbound = true;
                    break;
                }
            }
        }

        if (!$hasInbound) {
            $orphans[] = $path;
        }
    }

    return $orphans;
}

$raw = @file_get_contents(CSV_FILE);
if ($raw === false) {
    fwrite(STDERR, 'pages.csv: файл не найден: ' . CSV_FILE . "\n");
    exit(1);
}
$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;

$delimiter = detect_delimiter($raw);

$handle = fopen('php://temp', 'r+');
if ($handle === false) {
    fwrite(STDERR, "не удалось открыть временный поток\n");
    exit(1);
}
fwrite($handle, $raw);
rewind($handle);

// Номер физической строки: считаем переводы строк в прочитанных байтах,
// поэтому значения в кавычках с переносами внутри не сбивают нумерацию.
$lineNo = 1;
$offset = 0;
$advance = static function () use ($handle, $raw, &$lineNo, &$offset): void {
    $position = ftell($handle);
    if ($position !== false && $position > $offset) {
        $lineNo += substr_count($raw, "\n", $offset, $position - $offset);
        $offset = $position;
    }
};

$headerRow = fgetcsv($handle, 0, $delimiter, '"', '');
if ($headerRow === false) {
    fwrite(STDERR, "pages.csv: файл пуст\n");
    exit(1);
}
$advance();

$columns = [];
$header = array_map(static fn ($name): string => trim((string) $name), $headerRow);

foreach ($header as $position => $name) {
    if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
        add_error('заголовок: колонка "' . $name . '" недопустима: только a-z, 0-9 и "_"');
        continue;
    }
    if (isset($columns[$name])) {
        add_error('заголовок: колонка "' . $name . '" повторяется');
        continue;
    }
    $columns[$name] = $position;
}

foreach (REQUIRED_COLUMNS as $column) {
    if (!isset($columns[$column])) {
        add_error('заголовок: нет обязательной колонки "' . $column . '"');
    }
}

if ($errors !== []) {
    // Без валидной шапки разбирать строки бессмысленно: колонок не найти.
    foreach (array_slice($errors, 0, MAX_ERRORS) as $message) {
        fwrite(STDERR, $message . "\n");
    }
    exit(1);
}

$today = new DateTimeImmutable('today');
// lastmod «сегодня» по UTC ночью по Москве формально в будущем: допуск на сутки вперёд.
$lastmodLimit = $today->modify('+1 day');
$pages = [];
$pathFirst = [];
$titleFirst = [];
$h1First = [];
$descriptionFirst = [];

while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
    $recordLine = $lineNo;
    $advance();

    if ($row === [null]) {
        continue; // пустая строка между записями
    }

    $values = [];
    foreach ($columns as $name => $position) {
        $values[$name] = trim((string) ($row[$position] ?? ''));
    }

    // path
    $path = $values['path'];
    $pathOk = false;
    if ($path === '') {
        add_error('строка ' . $recordLine . ': поле "path" пустое');
    } elseif (preg_match('#^[a-z0-9-]+(?:/[a-z0-9-]+)*$#D', $path) !== 1) {
        add_error('строка ' . $recordLine . ': путь "' . $path . '" недопустим: только a-z, 0-9, дефис и "/" между сегментами');
    } elseif (strlen($path) > PATH_MAX_LENGTH) {
        add_error('строка ' . $recordLine . ': путь "' . $path . '" длиннее ' . PATH_MAX_LENGTH . ' символов');
    } elseif (count(explode('/', $path)) > 4) {
        add_error('строка ' . $recordLine . ': путь "' . $path . '" глубже 4 сегментов');
    } else {
        $pathOk = true;
    }

    if ($pathOk) {
        if (isset($pathFirst[$path])) {
            add_error('строка ' . $recordLine . ': путь "' . $path . '" повторяется (первая строка: ' . $pathFirst[$path] . ')');
            $pathOk = false;
        } else {
            $pathFirst[$path] = $recordLine;

            $firstSegment = explode('/', $path)[0];
            if (in_array($firstSegment, RESERVED_SEGMENTS, true)) {
                add_error('строка ' . $recordLine . ': первый сегмент "' . $firstSegment . '" совпадает с именем в корне сайта');
                $pathOk = false;
            }
        }
    }

    // type
    $type = $values['type'];
    if ($type === '') {
        add_error('строка ' . $recordLine . ': поле "type" пустое');
    } elseif (preg_match('/^[a-z0-9-]+$/', $type) !== 1 || !is_file(TPL_DIR . '/' . $type . '.html')) {
        add_error('строка ' . $recordLine . ': type "' . $type . '" не найден: нет файла templates/' . $type . '.html');
    }

    // обязательные текстовые поля
    foreach (['title', 'description', 'h1'] as $field) {
        if ($values[$field] === '') {
            add_error('строка ' . $recordLine . ': поле "' . $field . '" пустое');
        }
    }

    $title = $values['title'];
    if ($title !== '') {
        if (isset($titleFirst[$title])) {
            add_error('строка ' . $recordLine . ': дубль title "' . $title . '" (строка ' . $titleFirst[$title] . ')');
        } else {
            $titleFirst[$title] = $recordLine;
        }
    }

    $h1 = $values['h1'];
    if ($h1 !== '') {
        if (isset($h1First[$h1])) {
            add_warning('строка ' . $recordLine . ': дубль h1 "' . $h1 . '" (строка ' . $h1First[$h1] . ')');
        } else {
            $h1First[$h1] = $recordLine;
        }
    }

    $description = $values['description'];
    if ($description !== '') {
        if (isset($descriptionFirst[$description])) {
            add_warning('строка ' . $recordLine . ': дубль description (строка ' . $descriptionFirst[$description] . ')');
        } else {
            $descriptionFirst[$description] = $recordLine;
        }
    }

    // lastmod
    $lastmod = $values['lastmod'];
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $lastmod);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastmod) !== 1 || $parsed === false || $parsed->format('Y-m-d') !== $lastmod) {
        add_error('строка ' . $recordLine . ': lastmod "' . $lastmod . '" — нужен формат YYYY-MM-DD');
    } elseif ($parsed > $lastmodLimit) {
        add_error('строка ' . $recordLine . ': lastmod "' . $lastmod . '" в будущем');
    }

    // noindex
    $noindex = 0;
    if ($values['noindex'] !== '') {
        if ($values['noindex'] === '0' || $values['noindex'] === '1') {
            $noindex = (int) $values['noindex'];
        } else {
            add_error('строка ' . $recordLine . ': noindex "' . $values['noindex'] . '" — допустимо 0 или 1');
        }
    }

    $values['noindex'] = $noindex;
    $pages[] = ['line' => $recordLine, 'data' => $values, 'valid' => $pathOk];
}

// Второй проход: раздел-родитель должен существовать строкой.
// Строки с уже битым путём пропускаем: иначе одна ошибка порождает лавину вторичных.
$knownPaths = [];
foreach ($pages as $page) {
    $knownPaths[$page['data']['path']] = true;
}
foreach ($pages as $page) {
    if (!$page['valid']) {
        continue;
    }
    $segments = explode('/', $page['data']['path']);
    for ($depth = 1; $depth < count($segments); $depth++) {
        $prefix = implode('/', array_slice($segments, 0, $depth));
        if (!isset($knownPaths[$prefix])) {
            add_error(
                'строка ' . $page['line'] . ': нет страницы для раздела "' . $prefix . '" (нужна строка path=' . $prefix . ')'
            );
        }
    }
}

if (!isset($pathFirst[HOME_PATH])) {
    add_error('нет строки с path "' . HOME_PATH . '" (главная страница)');
}

// Проверка шаблонов: по одному разу на каждый используемый type.
// Отсутствующий шаблон уже отмечен в строке, повторно про него не пишем.
$checkedTypes = [];
foreach ($pages as $page) {
    $type = $page['data']['type'];
    if ($type === '' || isset($checkedTypes[$type]) || !is_file(TPL_DIR . '/' . $type . '.html')) {
        continue;
    }
    $checkedTypes[$type] = true;
    foreach (check_template($type, $columns) as $message) {
        $errors[] = $message;
    }
}

if ($errors !== []) {
    $shown = array_slice($errors, 0, MAX_ERRORS);
    foreach ($shown as $message) {
        fwrite(STDERR, $message . "\n");
    }
    if (count($errors) > MAX_ERRORS) {
        fwrite(STDERR, 'и ещё ' . (count($errors) - MAX_ERRORS) . "\n");
    }
    fwrite(STDERR, 'страниц не собрано, ошибок: ' . count($errors) . "\n");
    exit(1);
}

/** Цепочка предков: главная -> раздел -> ... без самой страницы. */
function ancestors_of(string $path, array $byPath): array
{
    $ancestors = [];
    $segments = explode('/', $path);

    for ($depth = 1; $depth < count($segments); $depth++) {
        $prefix = implode('/', array_slice($segments, 0, $depth));
        if (isset($byPath[$prefix])) {
            $ancestors[] = ['path' => $prefix, 'title' => (string) $byPath[$prefix]['title']];
        }
    }

    return $ancestors;
}

/** Сначала дочерние страницы, затем соседи: так у каждой есть входящие ссылки. */
function related_for(string $path, array $byPath, array $childrenOf): array
{
    $items = [];

    foreach ($childrenOf[$path] ?? [] as $child) {
        $items[$child] = ['path' => $child, 'title' => (string) $byPath[$child]['title']];
    }

    $parent = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';
    foreach ($childrenOf[$parent] ?? [] as $sibling) {
        if ($sibling === $path || isset($items[$sibling])) {
            continue;
        }
        $items[$sibling] = ['path' => $sibling, 'title' => (string) $byPath[$sibling]['title']];
    }

    ksort($items);

    return array_slice($items, 0, RELATED_LIMIT);
}

clear_dir(PAGES_DIR);

// Карта путей нужна и для крошек, и для блока «рядом»: считаем один раз.
$byPath = [];
foreach ($pages as $page) {
    $byPath[$page['data']['path']] = $page['data'];
}

$childrenOf = [];
foreach (array_keys($byPath) as $path) {
    $parent = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';
    $childrenOf[$parent][] = $path;
}
// Дети в порядке path: «Предыдущая / Следующая» и список раздела строятся на этом порядке.
foreach ($childrenOf as &$siblingList) {
    sort($siblingList, SORT_STRING);
}
unset($siblingList);

$relatedMap = related_map($byPath, $childrenOf);
$ancestorsMap = [];

$indexEntries = [];
foreach ($pages as $page) {
    $data = $page['data'];

    $ancestors = ancestors_of($data['path'], $byPath);
    $ancestorsMap[$data['path']] = $ancestors;
    $data['_canonical'] = page_url($data['path'], $siteUrl);
    $data['_breadcrumbs'] = breadcrumbs_html($ancestors, (string) $data['title'], $base);
    $data['_related'] = related_html($relatedMap[$data['path']], $base);

    // Раздел: полный список детей. Больше CHILDREN_MAX — пауза, пагинацию не придумываем.
    if ((string) $data['type'] === 'category') {
        $childPaths = $childrenOf[$data['path']] ?? [];
        if (count($childPaths) > CHILDREN_MAX) {
            build_fail(sprintf(
                'раздел %s: детей %d, пагинации нет — нужно решение заказчика',
                $data['path'],
                count($childPaths)
            ));
        }
        $data['_children'] = children_html(array_map(
            static fn (string $child): array => ['path' => $child, 'title' => (string) $byPath[$child]['title']],
            $childPaths
        ), $base);
    } else {
        $data['_children'] = '';
    }

    // Не-разделы: «Предыдущая / Следующая» среди детей того же родителя.
    if ((string) $data['type'] === 'category') {
        $data['_neighbors'] = '';
    } else {
        $siblings = $childrenOf[
            str_contains($data['path'], '/') ? substr($data['path'], 0, (int) strrpos($data['path'], '/')) : ''
        ] ?? [];
        $position = array_search($data['path'], $siblings, true);
        $prev = $position !== false && $position > 0
            ? ['path' => $siblings[$position - 1], 'title' => (string) $byPath[$siblings[$position - 1]]['title']]
            : null;
        $next = $position !== false && $position < count($siblings) - 1
            ? ['path' => $siblings[$position + 1], 'title' => (string) $byPath[$siblings[$position + 1]]['title']]
            : null;
        $data['_neighbors'] = neighbors_html($prev, $next, $base);
    }

    $data['_jsonld'] = jsonld_html($data, $ancestors, $siteUrl);

    write_file(PAGES_DIR . '/' . $data['path'] . '.json', json_encode_page($data));

    $indexEntries[] = [
        'path' => $data['path'],
        'type' => $data['type'],
        'title' => $data['title'],
        'lastmod' => $data['lastmod'],
        'noindex' => $data['noindex'],
    ];
}

usort($indexEntries, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

write_file(
    INDEX_FILE,
    json_encode_page([
        'version' => 1,
        'count' => count($indexEntries),
        'pages' => $indexEntries,
    ])
);

write_file(SITEMAP_FILE, sitemap_xml(array_map(static fn (array $page): array => $page['data'], $pages), $siteUrl));
write_file(ROBOTS_FILE, robots_txt($siteUrl, $hasSiteUrl));

// Финальная проверка SEO: страница без входящих ссылок не проиндексируется.
// Главная исключение (у неё по определению нет входящих).
$orphans = orphan_paths($byPath, $childrenOf, $relatedMap, $ancestorsMap);
if ($orphans !== []) {
    $shown = array_slice($orphans, 0, 20);
    fwrite(STDERR, 'страниц без входящих ссылок (сирот): ' . count($orphans) . "\n");
    foreach ($shown as $orphan) {
        fwrite(STDERR, '  ' . $orphan . "\n");
    }
    if (count($orphans) > 20) {
        fwrite(STDERR, '  …и ещё ' . (count($orphans) - 20) . "\n");
    }
    exit(1);
}

echo 'готово: ' . count($indexEntries) . " страниц\n";
