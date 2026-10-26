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
