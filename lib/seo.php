<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// SEO-разметка: canonical, robots, крошки, JSON-LD, sitemap, robots.txt.
// Всё считается на сборке и кладётся в data/pages/<path>.json готовым HTML:
// рантайм не должен читать весь индекс ради крошек на каждой странице.

const CRUMB_HOME_LABEL = 'Главная';
const RELATED_TITLE = 'Смотрите также';
const RELATED_LIMIT = 8;

// schema.org-тип страницы по нашему type. Неизвестный type — обычная WebPage.
const SCHEMA_TYPES = [
    'page' => 'WebPage',
    'category' => 'CollectionPage',
];

/** Экранирование для HTML-атрибутов и текста. */
function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Экранирование для XML: sitemap не прощает неэкранированный амперсанд. */
function esc_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function schema_type(string $type): string
{
    return SCHEMA_TYPES[$type] ?? 'WebPage';
}

/**
 * Крошки от главной к текущей странице.
 * $ancestors — уже разрешённые предки (path + title), без текущей страницы.
 * У главной предков нет, и блок не выводится совсем: пустой nav только мешает.
 */
function breadcrumbs_html(array $ancestors, string $currentTitle, string $base = ''): string
{
    if ($ancestors === []) {
        return '';
    }

    $items = '';
    foreach ($ancestors as $ancestor) {
        $items .= '<li class="breadcrumbs__item"><a href="'
            . esc($base . page_uri((string) $ancestor['path'])) . '">'
            . esc((string) $ancestor['title']) . '</a></li>';
    }
    $items .= '<li class="breadcrumbs__item" aria-current="page">' . esc($currentTitle) . '</li>';

    return '<nav class="breadcrumbs" aria-label="Хлебные крошки">'
        . '<ol class="breadcrumbs__list">' . $items . '</ol></nav>';
}

/** Блок «рядом»: у каждой страницы есть входящие внутренние ссылки. */
function related_html(array $items, string $base = ''): string
{
    if ($items === []) {
        return '';
    }

    $links = '';
    foreach (array_slice($items, 0, RELATED_LIMIT) as $item) {
        $links .= '<li class="related__item"><a href="'
            . esc($base . page_uri((string) $item['path'])) . '">'
            . esc((string) $item['title']) . '</a></li>';
    }

    return '<aside class="related"><h2 class="related__title">' . RELATED_TITLE . '</h2>'
        . '<ul class="related__list">' . $links . '</ul></aside>';
}

/**
 * JSON-LD: BreadcrumbList + страница нужного типа.
 * JSON_HEX_TAG обязателен: title из CSV не должен уметь закрыть <script>.
 */
function jsonld_html(array $page, array $ancestors, string $siteUrl): string
{
    // Главная ссылается сама на себя: BreadcrumbList из одной позиции не нужен.
    $crumbs = [];
    if ((string) $page['path'] !== HOME_PATH) {
        $crumbs[] = ['path' => HOME_PATH, 'title' => CRUMB_HOME_LABEL];
    }
    foreach ($ancestors as $ancestor) {
        $crumbs[] = ['path' => (string) $ancestor['path'], 'title' => (string) $ancestor['title']];
    }
    $crumbs[] = ['path' => (string) $page['path'], 'title' => (string) $page['title']];

    $graph = [];

    if (count($crumbs) > 1) {
        $list = [];
        foreach ($crumbs as $index => $crumb) {
            $list[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['title'],
                'item' => page_url($crumb['path'], $siteUrl),
            ];
        }
        $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
    }

    $url = page_url((string) $page['path'], $siteUrl);
    $node = [
        '@type' => schema_type((string) $page['type']),
        '@id' => $url,
        'url' => $url,
        'name' => (string) $page['title'],
        'inLanguage' => 'ru',
    ];
    if ((string) ($page['description'] ?? '') !== '') {
        $node['description'] = (string) $page['description'];
    }
    $graph[] = $node;

    $json = json_encode(
        ['@context' => 'https://schema.org', '@graph' => $graph],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
    );
    if ($json === false) {
        throw new RuntimeException('JSON-LD не собрался: ' . json_last_error_msg());
    }

    return '<script type="application/ld+json">' . $json . '</script>';
}

/** sitemap.xml: только индексируемые страницы, lastmod из данных. */
function sitemap_xml(array $pages, string $siteUrl): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach ($pages as $page) {
        if ((int) ($page['noindex'] ?? 0) === 1) {
            continue;
        }
        $xml .= "  <url>\n"
            . '    <loc>' . esc_xml(page_url((string) $page['path'], $siteUrl)) . "</loc>\n"
            . '    <lastmod>' . esc_xml((string) $page['lastmod']) . "</lastmod>\n"
            . "  </url>\n";
    }

    return $xml . "</urlset>\n";
}

/**
 * robots.txt. Строка Sitemap имеет смысл только с настоящим доменом:
 * с localhost-заглушкой она уводит поисковик в никуда, поэтому её тогда нет.
 */
function robots_txt(string $siteUrl, bool $withSitemap): string
{
    $text = "User-agent: *\n";
    foreach (DENY_PREFIXES as $prefix) {
        $text .= 'Disallow: /' . $prefix . "/\n";
    }

    if ($withSitemap) {
        $text .= "\nSitemap: " . rtrim($siteUrl, '/') . "/sitemap.xml\n";
    }

    return $text;
}
