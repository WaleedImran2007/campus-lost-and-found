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

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ----------------------------------------------------------- rate limits
/*
 * Fixed-window counters stored in RATE_LIMITS.
 * rate_blocked() = "is this key already at the limit?"   rate_hit() = "count one more".
 * No user row is needed, so it works for guests and unknown emails.
 */
function rate_window_start(int $seconds): string
{
    return date('Y-m-d H:i:s', intdiv(time(), $seconds) * $seconds);
}

function rate_blocked(string $name, string $identity): bool
{
    [$max, $window] = RATE_LIMITS[$name];
    $stmt = db()->prepare('SELECT hit_count FROM rate_limits WHERE limit_key = ? AND window_start = ?');
    $stmt->execute([$name . ':' . $identity, rate_window_start($window)]);
    return (int) $stmt->fetchColumn() >= $max;
}

function rate_hit(string $name, string $identity): void
{
    [, $window] = RATE_LIMITS[$name];
    $stmt = db()->prepare(
        'INSERT INTO rate_limits (limit_key, window_start, hit_count) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE hit_count = hit_count + 1'
    );
    $stmt->execute([$name . ':' . $identity, rate_window_start($window)]);

    if (random_int(1, 50) === 1) {                 // purge old rows now and then
        db()->exec('DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 2 DAY');
    }
}

function rate_message(string $name): string
{
    [$max, $window] = RATE_LIMITS[$name];
    $when = $window >= 86400 ? 'per day' : ($window >= 3600 ? 'per hour' : 'in ' . ($window / 60) . ' minutes');
    return "Limit reached ($max $when). Please try again later.";
}

// ------------------------------------------------------- audit + notify
/** Insert one audit row. Call it INSIDE the same transaction as the change. */
function audit_log(?int $actorId, string $action, string $targetType, int $targetId, ?string $details = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO audit_logs (actor_id, action, target_type, target_id, details, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$actorId, $action, $targetType, $targetId, $details, client_ip()]);
}

function notify(int $userId, string $type, string $text, ?int $itemId = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO notifications (user_id, related_item_id, notification_type, notification_text)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $itemId, $type, mb_substr($text, 0, 255)]);
}

function unread_notifications(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function unread_messages(int $userId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND message_status = 'sent'");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

// ------------------------------------------------------------- lookups
function active_categories(): array
{
    return db()->query('SELECT category_id, category_name FROM categories WHERE is_active = 1 ORDER BY category_name')->fetchAll();
}

function active_locations(): array
{
    return db()->query('SELECT location_id, location_name FROM campus_locations WHERE is_active = 1 ORDER BY location_name')->fetchAll();
}

// --------------------------------------------------------------- badges
function status_label(string $s): string
{
    return ucwords(str_replace('_', ' ', $s));
}

function badge(string $status): string
{
    return '<span class="badge badge-' . e($status) . '">' . e(status_label($status)) . '</span>';
}

function fmt_dt(?string $dt): string
{
    return $dt ? date('d M Y, H:i', strtotime($dt)) : '-';
}

function fmt_date(?string $d): string
{
    return $d ? date('d M Y', strtotime($d)) : '-';
}

function time_ago(string $dt): string
{
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return intdiv($diff, 60) . ' min ago';
    if ($diff < 86400) return intdiv($diff, 3600) . ' h ago';
    return intdiv($diff, 86400) . ' d ago';
}

// -------------------------------------------------------------- uploads
const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

const EVIDENCE_TYPES = IMAGE_TYPES + ['application/pdf' => 'pdf'];

/** Turn $_FILES['x'] (single or multiple) into a flat list of real uploads. */
