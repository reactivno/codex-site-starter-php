<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** Файл кэша: cache/home.html, cache/uslugi/remont-kvartir.html. */
function cache_file(string $path): string
{
    return CACHE_DIR . '/' . $path . '.html';
}

/**
 * Атомарная запись кэша: временный файл рядом с целевым, затем rename().
 * Читатель никогда не увидит недописанный HTML, даже если запись оборвётся.
 * false означает «закэшировать не удалось» — страница при этом уже отрендерена.
 */
function cache_store(string $path, string $html): bool
{
    $target = cache_file($path);
    $dir = dirname($target);

    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    $tmp = @tempnam($dir, 'tmp-');
    if ($tmp === false) {
        return false;
    }
    if (@file_put_contents($tmp, $html) === false) {
        @unlink($tmp);

        return false;
    }

    // tempnam() создаёт файл с правами 0600: после rename() Apache не смог бы
    // его прочитать, если кэш писал warmup.php из CLI.
    @chmod($tmp, 0644);

    if (!@rename($tmp, $target)) {
        @unlink($tmp);

        return false;
    }

    return true;
}
