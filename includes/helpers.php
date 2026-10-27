<?php
/* Small shared helpers: escaping, URLs, flash messages, CSRF, rate limits,
   audit log, notifications, uploads. */

// ---------------------------------------------------------------- output
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// ------------------------------------------------------------------ URLs
/** URL path of the /public folder (auto-detected, so subfolders work). */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    if (BASE_URL_OVERRIDE !== '') {
        return $base = rtrim(BASE_URL_OVERRIDE, '/');
    }
    $public = str_replace('\\', '/', realpath(__DIR__ . '/../public'));
    $root   = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
    if ($root !== '' && strncasecmp($public, $root, strlen($root)) === 0) {
        return $base = rtrim(substr($public, strlen($root)), '/');
    }
    return $base = '/campus-lost-found/public';
}

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

// ----------------------------------------------------------------- flash
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_render(): string
{
    $html = '';

    foreach ($_SESSION['flash'] ?? [] as $f) {
        $html .= '<div class="flash flash-' . e($f['type']) . '" role="alert">' . e($f['message']) . '</div>';
    }

    unset($_SESSION['flash']);
    return $html;
}

// ------------------------------------------------------------------ CSRF
/** Hidden field for normal buttons/forms (token lives for the whole session). */
function csrf_field(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf']) . '">';
}

function csrf_ok(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf']) && is_string($sent) && hash_equals($_SESSION['csrf'], $sent);
}

/** SINGLE-USE token for "create" forms. A double click cannot create two rows (Business Rule 13). */
function once_field(): string
{
    $token = bin2hex(random_bytes(16));
    $_SESSION['once'][$token] = time();
    if (count($_SESSION['once']) > 40) {          // keep the list small
        array_shift($_SESSION['once']);
    }
    return '<input type="hidden" name="once_token" value="' . $token . '">';
}

/** Consumes the token: returns true only the FIRST time it is seen. */
function once_ok(): bool
{
    $sent = $_POST['once_token'] ?? '';
    if (is_string($sent) && isset($_SESSION['once'][$sent])) {
        unset($_SESSION['once'][$sent]);
        return true;
    }
    return false;
}

/** Call at the top of every POST handler that uses csrf_field(). */
function require_csrf(string $backTo): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) {
        flash('error', 'Security check failed. Please try again.');
        redirect($backTo);
    }
}

// ------------------------------------------------------------ input utils
function post(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

function get_int(string $key): int
{
    return (int) ($_GET[$key] ?? 0);
}

function post_int(string $key): int
{
    return (int) ($_POST[$key] ?? 0);
}

function str_len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

/** Only @pucit.edu.pk addresses are accepted (checked on the server). */
function valid_university_email(string $email): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $parts = explode('@', $email);

    return strtolower(end($parts)) === ALLOWED_EMAIL_DOMAIN;
}

function valid_password(string $pw): ?string
{
    if (strlen($pw) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        return 'Password must contain at least one letter and one number.';
    }
    return null;
}

