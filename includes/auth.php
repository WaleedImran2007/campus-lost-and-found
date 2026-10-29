<?php
/*
 * Session login + role checks (same idea as the RBAC lesson).
 * Difference from the lesson: the role and suspension state are re-read from
 * the DATABASE on every request (Business Rule 1), not trusted from the session.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

/** Current user row from the database (cached for this request), or null. */
function currentUser(): ?array
{
    static $user = false;

    if ($user !== false) {
        return $user;
    }

    if (!isLoggedIn()) {
        return $user = null;
    }

    $stmt = db()->prepare(
        'SELECT 
            user_id, student_id, full_name, email, phone, role, account_status, suspended_until, suspension_reason
        FROM users WHERE user_id = ?'
    );
    
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();

    if (!$row || $row['account_status'] === 'deactivated') {
        $_SESSION = [];
        session_destroy();
        return $user = null;
    }

    // A suspension whose end date has passed counts as lifted.
    $row['is_suspended'] = $row['account_status'] === 'suspended'
        && ($row['suspended_until'] === null || strtotime($row['suspended_until']) > time());

    return $user = $row;
}

function isStaff(?array $u): bool
{
    if ($u === null) {
        return false;
    }

    if ($u['role'] === 'custodian' || $u['role'] === 'admin') {
        return true;
    }

    return false;
}

function requireLogin(): array
{
    $u = currentUser();
    if ($u === null) {
        flash('error', 'Please log in first.');
        redirect('login.php');
    }
    return $u;
}


function requireRole($roles): array
{
    $u = requireLogin();
    $roles = (array) $roles;

    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        page_header('Access denied');

        echo '<div class="card narrow"><h1>403 - Access denied</h1>'
           . '<p>Your account does not have permission to open this page.</p>'
           . '<p><a class="btn" href="' . e(url('dashboard.php')) . '">Back to dashboard</a></p></div>';
           
        page_footer();
        exit;
    }
    return $u;
}

/** Why this user may not post / claim / report / message (or null if fine). */
function actionBlockReason(array $u): ?string
{
    if ($u['is_suspended']) {
        $why = $u['suspension_reason'] ? ' Reason: ' . $u['suspension_reason'] : '';
        $until = $u['suspended_until'] ? ' until ' . date('d M Y, H:i', strtotime($u['suspended_until'])) : '';
        return 'Your account is suspended' . $until . '.' . $why;
    }
    return null;
}

/** Student that is allowed to post / claim / report. Otherwise flash + redirect. */
function requireActiveStudent(): array
{
    $u = requireRole('student');
    if ($reason = actionBlockReason($u)) {
        flash('error', $reason);
        redirect('dashboard.php');
    }
    return $u;
}

/** Any active user (students and staff) that may send messages. */
function requireActiveUser(): array
{
    $u = requireLogin();
    if ($reason = actionBlockReason($u)) {
        flash('error', $reason);
        redirect('dashboard.php');
    }
    return $u;
}
