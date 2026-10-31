<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$u = requireLogin();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Security check failed. Please try again.';
    } elseif (post('action') === 'profile') {
        $name  = post('full_name');
        $phone = post('phone');
        if (str_len($name) < 3 || str_len($name) > 100) $errors[] = 'Full name must be 3 to 100 characters.';
        if ($phone !== '' && !preg_match('/^[0-9+\- ]{7,20}$/', $phone)) $errors[] = 'Invalid phone number.';
        if (!$errors) {
            db()->prepare('UPDATE users SET full_name = ?, phone = ? WHERE user_id = ?')
                ->execute([$name, $phone === '' ? null : $phone, $u['user_id']]);
            flash('success', 'Profile updated.');
            redirect('profile.php');
        }
    } elseif (post('action') === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE user_id = ?');
        $stmt->execute([$u['user_id']]);
        if (!password_verify($current, (string) $stmt->fetchColumn())) $errors[] = 'Current password is wrong.';
        if ($pw = valid_password($new)) $errors[] = $pw;
        if ($new !== $confirm) $errors[] = 'New passwords do not match.';
        if (!$errors) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $u['user_id']]);
            session_regenerate_id(true);
            flash('success', 'Password changed.');
            redirect('profile.php');
        }
    }
}

page_header('My profile', 'profile');
?>
<h1>My profile</h1>
<?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="grid grid-2">
    <div class="card">
        <h2>Details</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="profile">
            <label>Email</label><input type="text" value="<?= e($u['email']) ?>" disabled>
            <label>Roll number</label><input type="text" value="<?= e($u['student_id'] ?? '-') ?>" disabled>
            <label for="full_name">Full name</label>
            <input type="text" id="full_name" name="full_name" value="<?= e($_POST['full_name'] ?? $u['full_name']) ?>" maxlength="100" required>
            <label for="phone">Phone</label>
            <input type="text" id="phone" name="phone" value="<?= e($_POST['phone'] ?? ($u['phone'] ?? '')) ?>" maxlength="20">
            <p><button class="btn" type="submit">Save</button></p>
        </form>
    </div>
    <div class="card">
        <h2>Change password</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="password">
            <label for="current_password">Current password</label>
            <input type="password" id="current_password" name="current_password" required>
            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password" data-strength required>
            <label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password" required>
            <p><button class="btn" type="submit">Change password</button></p>
        </form>
    </div>
</div>
<?php page_footer();
