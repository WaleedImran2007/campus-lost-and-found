<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (currentUser()) {
    redirect('dashboard.php');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(post('email'));
    $password = $_POST['password'] ?? '';

    // Throttle by email AND by IP. Works even if the email does not exist (no user row needed).
    $emailKey = sha1($email);
    $ipKey    = client_ip();

    if (!csrf_ok()) {
        $error = 'Security check failed. Please reload the page.';
    } elseif (rate_blocked('login_fail', 'email:' . $emailKey) || rate_blocked('login_fail', 'ip:' . $ipKey)) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        $stmt = db()->prepare('SELECT user_id, password_hash, account_status FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // verify against a dummy hash when the user does not exist (keeps timing similar)
        $hash = $user['password_hash'] ?? '$2y$10$JxeYPuqZojsSf8B4RvUMfuDSNYjbgUyn1hlqZ4GDnyEYH3jmbcWbi';
        $ok   = password_verify($password, $hash) && $user;

        if ($ok && $user['account_status'] !== 'deactivated') {
            session_regenerate_id(true);                       // stop session fixation
            $_SESSION['user_id'] = (int) $user['user_id'];
            flash('success', 'Welcome back!');
            redirect('dashboard.php');
        }

        rate_hit('login_fail', 'email:' . $emailKey);
        rate_hit('login_fail', 'ip:' . $ipKey);
        $error = 'Invalid email or password.';
    }
}

page_header('Login', 'login');
?>
<div class="card narrow">
    <h1>Log in</h1>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <label for="email">University email</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus>
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
        <p><button class="btn" type="submit">Log in</button></p>
    </form>
    <p class="small">New here? <a href="<?= e(url('register.php')) ?>">Create an account</a></p>
</div>
<?php page_footer();
