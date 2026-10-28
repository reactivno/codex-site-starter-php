<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Сырые вставки: без экранирования выводятся только эти поля.
// Всё остальное проходит через htmlspecialchars — данные приходят из CSV,
// который правит человек, и попадают в HTML. `_robots` — готовая meta-строка от кода.
const RAW_FIELDS = ['body', '_breadcrumbs', '_related', '_jsonld', '_robots'];

const PARTIAL_MAX_DEPTH = 3;

/**
 * Шаблон + данные -> HTML.
 * Любая проблема (нет шаблона, нет поля, битый partial) — RuntimeException:
 * роутер превратит его в 503, а не отдаст страницу с пустыми местами.
 */
function render(string $type, array $page): string
{
    if (preg_match('/^[a-z0-9-]+$/', $type) !== 1) {
        throw new RuntimeException('Недопустимый type: ' . $type);
    }

    $file = TPL_DIR . '/' . $type . '.html';
    $html = @file_get_contents($file);
    if ($html === false) {
        throw new RuntimeException('Нет шаблона ' . $file);
    }

    return substitute(expand_partials($html, []), render_values($page), $type);
}

/** Поля страницы плюс значения, которые считает код, а не CSV. */
function render_values(array $page): array
{
    $path = (string) ($page['path'] ?? HOME_PATH);

    $values = $page;
    // _canonical приходит из сборки; запасной расчёт — для страниц без сборки.
    $values['_canonical'] ??= page_url($path);
    $values['_base'] = site_base();
    $values['_form_action'] = $values['_base'] . '/php/send.php';
    $values['_robots'] = (int) ($page['noindex'] ?? 0) === 1
        ? '<meta name="robots" content="noindex">'
        : '';
    $values['_breadcrumbs'] ??= '';
    $values['_related'] ??= '';
    $values['_jsonld'] ??= '';

    return $values;
}

/** {{ include имя}} -> partials/имя.html, рекурсивно, с защитой от цикла и глубины. */
function expand_partials(string $html, array $stack): string
{
    $result = preg_replace_callback(
        '/\{\{\s*include\s+([a-z0-9-]+)\s*\}\}/',
        static function (array $match) use ($stack): string {
            $name = $match[1];

            if (in_array($name, $stack, true)) {
                throw new RuntimeException('Цикл partials: ' . implode(' -> ', [...$stack, $name]));
            }
            if (count($stack) >= PARTIAL_MAX_DEPTH) {
                throw new RuntimeException('Вложенность partials больше ' . PARTIAL_MAX_DEPTH);
            }

            $file = PARTIAL_DIR . '/' . $name . '.html';
            $partial = @file_get_contents($file);
            if ($partial === false) {
                throw new RuntimeException('Нет partial ' . $file);
            }

            return expand_partials($partial, [...$stack, $name]);
        },
        $html
    );

    if ($result === null) {
        throw new RuntimeException('Не удалось разобрать partials');
    }

    // Старый синтаксис {{> имя}} похож на Mustache-«unescape» и путает линтеры:
    // лучше упасть здесь, чем молча вывести его в готовую страницу.
    if (preg_match('/\{\{\s*>\s*[a-z0-9-]+\s*\}\}/', $result) === 1) {
        throw new RuntimeException('Синтаксис {{> имя}} отменён: подключение partials пишется как {{ include имя }}');
    }

    return $result;
}

/** {{{поле}}} и {{поле}}. Сначала сырые вставки, иначе фигурные скобки съедят друг друга. */
function substitute(string $html, array $values, string $type): string
{
    $missing = [];

    $html = preg_replace_callback(
        '/\{\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}\}/i',
        static function (array $match) use ($values, $type, &$missing): string {
            $field = $match[1];
            if (!array_key_exists($field, $values)) {
                $missing[] = $field;

                return '';
            }
            if (!in_array($field, RAW_FIELDS, true)) {
                throw new RuntimeException(
                    'templates/' . $type . '.html: поле "' . $field . '" нельзя выводить без экранирования'
                );
            }

            return (string) $values[$field];
        },
        $html
    );

    $html = preg_replace_callback(
        '/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/i',
        static function (array $match) use ($values, &$missing): string {
            $field = $match[1];
            if (!array_key_exists($field, $values)) {
                $missing[] = $field;

                return '';
            }

            return htmlspecialchars((string) $values[$field], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        },
        $html
    );

    if ($missing !== []) {
        $missing = array_values(array_unique($missing));
        sort($missing);
        throw new RuntimeException($type . ': в данных нет полей ' . implode(', ', $missing));
    }

    return (string) $html;
}
