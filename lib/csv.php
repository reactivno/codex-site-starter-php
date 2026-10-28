<?php

declare(strict_types=1);

// Единый разбор CSV для split-data.php и build.php.
// Excel в русской локали пишет разделитель ";" и BOM в начале файла;
// фотки/описания могут содержать переносы строк внутри кавычек. Построчный
// preg_split ломается на обоих случаях, поэтому здесь fgetcsv по потоку,
// а номер физической строки считаем по прочитанным байтам, а не по записям.

const CSV_BOM = "\xEF\xBB\xBF";
const CSV_DELIMITERS = [',', ';', "\t"];

/**
 * Разбор CSV: BOM, автоопределение разделителя, многострочные значения.
 *
 * Возвращает ['header' => [имена колонок в порядке шапки],
 *              'rows' => [['line' => N, 'values' => [колонка => строке]], ...]].
 * line — физический номер строки файла (шапка = 1), чтобы сообщение об ошибке
 * указывало на ту строку, которую видит человек в редакторе.
 */
function csv_parse(string $raw): array
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    $delimiter = csv_detect_delimiter($raw);

    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        throw new RuntimeException('csv_parse: не удалось открыть временный поток');
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
        throw new RuntimeException('csv_parse: файл пуст');
    }
    $advance();

    $header = array_map(static fn ($name): string => trim((string) $name), $headerRow);

    $rows = [];
    while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
        $line = $lineNo;
        $advance();

        if ($row === [null]) {
            continue; // пустая строка между записями
        }

        $values = [];
        foreach ($header as $i => $name) {
            if ($name === '') {
                continue;
            }
            $values[$name] = (string) ($row[$i] ?? '');
        }
        $rows[] = ['line' => $line, 'values' => $values];
    }
    fclose($handle);

    return ['header' => $header, 'rows' => $rows];
}

/**
 * Разделитель определяем по шапке: Excel в русской локали пишет ";" и BOM.
 * Кандидаты считаем только в первой физической строке, чтобы значения
 * с запятыми в теле файла не сместили выбор.
 */
function csv_detect_delimiter(string $raw): string
{
    $firstLine = strstr($raw, "\n", true);
    if ($firstLine === false) {
        $firstLine = $raw;
    }

    $best = ',';
    $bestCount = 0;
    foreach (CSV_DELIMITERS as $candidate) {
        $count = substr_count($firstLine, $candidate);
        if ($count > $bestCount) {
            $best = $candidate;
            $bestCount = $count;
        }
    }

    return $best;
}

/**
 * Стабильное представление строки для сравнения: порядок колонок и лишние
 * пробелы не влияют на результат. Используется changed_paths() в build.php,
 * чтобы «изменена» означало смену содержимого, а не форматирования.
 */
function csv_normalize(array $values): string
{
    $clean = [];
    foreach ($values as $column => $value) {
        $clean[(string) $column] = trim((string) $value);
    }
    ksort($clean);

    $parts = [];
    foreach ($clean as $column => $value) {
        $parts[] = $column . '=' . $value;
    }

    return implode("\n", $parts);
}
