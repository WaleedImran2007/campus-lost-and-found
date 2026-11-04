<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$u   = requireRole('admin');
$pdo = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Security check failed. Please try again.';
    } else {
        $action = post('action');
        $target = post_int('user_id');
        if ($action === 'suspend') {
            $days = post('days') === '' ? null : max(1, min(365, post_int('days')));   // blank = open-ended
            [$ok, $msg] = suspend_user($target, $u, $days, post('reason'));
        } elseif ($action === 'reactivate') {
            [$ok, $msg] = reactivate_user($target, $u);
        } elseif ($action === 'role') {
            [$ok, $msg] = change_role($target, $u, post('role'));
        } elseif ($action === 'create_custodian') {
            $name = post('full_name'); $email = strtolower(post('email')); $pw = $_POST['password'] ?? '';
            if (str_len($name) < 3 || str_len($name) > 100)  { [$ok, $msg] = [false, 'Name must be 3 to 100 characters.']; }
            elseif (!valid_university_email($email))          { [$ok, $msg] = [false, 'Email must end with @' . ALLOWED_EMAIL_DOMAIN . '.']; }
            elseif ($pwErr = valid_password($pw))             { [$ok, $msg] = [false, $pwErr]; }
            else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'custodian')")
                        ->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT)]);
                    audit_log((int) $u['user_id'], 'custodian_created', 'user', (int) $pdo->lastInsertId(), $email);
                    $pdo->commit();
                    [$ok, $msg] = [true, 'Custodian account created.'];
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    [$ok, $msg] = [false, $e->getCode() === '23000' ? 'That email is already registered.' : 'Could not create the account.'];
                }
            }
        } else {
            [$ok, $msg] = [false, 'Unknown action.'];
        }
        flash($ok ? 'success' : 'error', $msg);
        redirect('admin/users.php' . (isset($_GET['q']) ? '?q=' . urlencode($_GET['q']) : ''));
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$where = '1=1'; $params = [];
if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $where = '(full_name LIKE ? OR email LIKE ? OR student_id LIKE ?)';
    $params = [$like, $like, $like];
}
$cnt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $where"); $cnt->execute($params);
$pages = max(1, (int) ceil($cnt->fetchColumn() / $perPage));
$page = min($page, $pages);

$stmt = $pdo->prepare("SELECT user_id, student_id, full_name, email, role, account_status, suspended_until, suspension_reason, created_at
                       FROM users WHERE $where ORDER BY user_id LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage));
$stmt->execute($params);
$list = $stmt->fetchAll();

page_header('Users', 'admin');
?>
<h1>Users</h1>
<?php admin_subnav('users.php'); ?>
<?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>

<details class="card">
    <summary>+ Create a custodian account</summary>
    <form method="post" class="grid grid-2" style="margin-top:10px">
        <?= csrf_field() ?><input type="hidden" name="action" value="create_custodian">
        <div><label>Full name</label><input type="text" name="full_name" maxlength="100" required></div>
        <div><label>Email (@<?= e(ALLOWED_EMAIL_DOMAIN) ?>)</label><input type="email" name="email" maxlength="150" required></div>
        <div><label>Temporary password</label><input type="password" name="password" required></div>
        <div style="align-self:end"><button class="btn" type="submit">Create custodian</button></div>
    </form>
</details>

<form class="card row" method="get">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email or roll number" style="max-width:360px">
    <button class="btn" type="submit">Search</button>
</form>

<div class="card table-wrap">
    <table>
        <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($list as $r):
            $suspendedNow = $r['account_status'] === 'suspended' && ($r['suspended_until'] === null || strtotime($r['suspended_until']) > time()); ?>
            <tr>
                <td><strong><?= e($r['full_name']) ?></strong><br><span class="muted small"><?= e($r['email']) ?> &middot; <?= e($r['student_id'] ?? '-') ?></span></td>
                <td>
                    <form method="post" class="row" style="flex-wrap:nowrap">
                        <?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="user_id" value="<?= (int) $r['user_id'] ?>">
                        <select name="role" style="width:auto">
                            <?php foreach (['student', 'custodian', 'admin'] as $role): ?><option value="<?= $role ?>" <?= $r['role'] === $role ? 'selected' : '' ?>><?= $role ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn ghost small" type="submit">Set</button>
                    </form>
                </td>
                <td><?= badge($suspendedNow ? 'suspended' : ($r['account_status'] === 'suspended' ? 'active' : $r['account_status'])) ?>
                    <?php if ($suspendedNow): ?><br><span class="muted small"><?= $r['suspended_until'] ? 'until ' . e(fmt_dt($r['suspended_until'])) : 'open-ended' ?><br><?= e($r['suspension_reason'] ?? '') ?></span><?php endif; ?></td>
                <td>
                    <?php if ($r['account_status'] === 'suspended'): ?>
                        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="reactivate"><input type="hidden" name="user_id" value="<?= (int) $r['user_id'] ?>">
                            <button class="btn success small" type="submit">Reactivate</button></form>
                    <?php elseif ((int) $r['user_id'] !== (int) $u['user_id']): ?>
                        <details><summary class="small">Suspend...</summary>
                            <form method="post">
                                <?= csrf_field() ?><input type="hidden" name="action" value="suspend"><input type="hidden" name="user_id" value="<?= (int) $r['user_id'] ?>">
                                <input type="number" name="days" min="1" max="365" placeholder="days (blank = open-ended)" style="margin:6px 0">
                                <input type="text" name="reason" placeholder="Reason (required)" maxlength="255" required>
                                <button class="btn danger small" type="submit" style="margin-top:6px">Suspend</button>
                            </form></details>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if ($pages > 1): ?><div class="pager"><?php for ($p = 1; $p <= $pages; $p++): ?><?= $p === $page ? '<span class="cur">' . $p . '</span>' : '<a href="' . e(page_link($p)) . '">' . $p . '</a>' ?><?php endfor; ?></div><?php endif; ?>
<?php page_footer();
