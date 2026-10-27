<?php

declare(strict_types=1);

// Обработчик формы, нейтральный к набору полей.
// Под новую форму правится только массив FORM_CONFIG: имена обязательных полей,
// поле с email, поле-ловушка и лимиты. Остальной код менять не нужно.
//
// ВАЖНО: имена в FORM_CONFIG должны совпадать с атрибутами name="..." в HTML.
// Меняешь поля формы — правь HTML и FORM_CONFIG одним коммитом.

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const FORM_CONFIG = [
    'required' => ['phone', 'consent'],        // без этих полей заявка не принимается
    'email' => 'email',                        // поле с email; пустая строка — проверки нет
    'honeypot' => 'website',                   // поле-ловушка для ботов; пустая строка — выключено
    'max_length' => 5000,                      // предел длины одного поля
    'max_body' => 65536,                       // предел размера всей заявки
];

/**
 * Ответ и выход: клиент всегда получает JSON, а не HTML-ошибку.
 */
function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Значение из окружения сайта: getenv(), затем $_ENV, затем $_SERVER.
 */
function env(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }

    return trim((string) $value);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Метод не поддерживается']);
}

$honeypot = FORM_CONFIG['honeypot'];
if ($honeypot !== '' && trim((string) ($_POST[$honeypot] ?? '')) !== '') {
    respond(200, ['ok' => true]); // боту отвечаем как обычно, письмо не шлём
}

$fields = [];
$errors = [];
$bodySize = 0;

foreach ($_POST as $fieldName => $value) {
    $fieldName = (string) $fieldName;

    if ($honeypot !== '' && $fieldName === $honeypot) {
        continue;
    }
    if (preg_match('/^[a-z0-9_-]{1,40}$/i', $fieldName) !== 1) {
        continue; // имена с мусором в письмо не переносим
    }
    if (!is_string($value)) {
        $errors[] = 'Поле «' . $fieldName . '»: ожидается текст.';
        continue;
    }

    $value = trim($value);
    if (mb_strlen($value) > FORM_CONFIG['max_length']) {
        $errors[] = 'Поле «' . $fieldName . '»: не больше ' . FORM_CONFIG['max_length'] . ' символов.';
        continue;
    }
    if ($value === '') {
        continue;
    }

    $bodySize += mb_strlen($fieldName) + mb_strlen($value);
    $fields[$fieldName] = $value;
}

foreach (FORM_CONFIG['required'] as $requiredField) {
    if (!isset($fields[$requiredField])) {
        $errors[] = 'Поле «' . $requiredField . '»: заполните.';
    }
}

$emailField = FORM_CONFIG['email'];
$email = $emailField !== '' ? ($fields[$emailField] ?? '') : '';
if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $errors[] = 'Поле «' . $emailField . '»: некорректный email.';
}

if ($bodySize > FORM_CONFIG['max_body']) {
    respond(413, ['ok' => false, 'error' => 'Заявка слишком большая.']);
}

if ($errors !== []) {
    respond(422, ['ok' => false, 'errors' => $errors]);
}

if ($fields === []) {
    respond(422, ['ok' => false, 'error' => 'Пустая заявка.']);
}

$to = env('MAIL_TO');
if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    respond(500, ['ok' => false, 'error' => 'Получатель не настроен.']);
}

$from = env('MAIL_FROM');
if ($from === '') {
    // Домен берём из конфигурации сервера, а не из заголовка Host от клиента.
    $host = preg_replace('/[^a-z0-9.\-]/i', '', (string) ($_SERVER['SERVER_NAME'] ?? ''));
    $from = $host !== '' ? 'no-reply@' . $host : $to;
}
if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
    $from = $to;
}

// mail() не кодирует заголовки сам: кириллица без RFC 2047 приходит пустой.
$prefix = env('MAIL_SUBJECT_PREFIX');
$subject = mb_encode_mimeheader(trim('Заявка с сайта ' . $prefix), 'UTF-8', 'B');

$body = '';
foreach ($fields as $fieldName => $value) {
    $body .= $fieldName . ': ' . $value . "\n";
}

$headers = [
    'From: ' . $from,
    'Content-Type: text/plain; charset=utf-8',
    'Content-Transfer-Encoding: 8bit',
    'MIME-Version: 1.0',
];
if ($email !== '') {
    // в заголовок попадает только адрес, уже прошедший FILTER_VALIDATE_EMAIL
    $headers[] = 'Reply-To: ' . $email;
}

if (mail($to, $subject, $body, implode("\r\n", $headers)) === false) {
    respond(500, ['ok' => false, 'error' => 'Письмо не отправилось. Попробуйте позже.']);
}

respond(200, ['ok' => true]);
