<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (currentUser()) {
    redirect('dashboard.php');
}

$errors = [];
$old = ['full_name' => '', 'student_id' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = [
        'full_name'  => post('full_name'),
        'student_id' => strtolower(post('student_id')),
        'email'      => strtolower(post('email')),
        'phone'      => post('phone'),
    ];
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!once_ok()) {
        $errors[] = 'This form was already submitted or has expired. Please try again.';
    } elseif (rate_blocked('register', client_ip())) {
        $errors[] = rate_message('register');
    } else {
        if (str_len($old['full_name']) < 3 || str_len($old['full_name']) > 100) {
            $errors[] = 'Full name must be 3 to 100 characters.';
        }
        if (!preg_match('/^[a-z0-9]{4,20}$/', $old['student_id'])) {
            $errors[] = 'Roll number must be 4 to 20 letters/numbers (example: bcsf24m035).';
        }
        if (!valid_university_email($old['email'])) {
            $errors[] = 'Use your university email ending with @' . ALLOWED_EMAIL_DOMAIN . '.';
        } elseif (strlen($old['email']) > 150) {
            $errors[] = 'Email is too long.';
        }
        if ($old['phone'] !== '' && !preg_match('/^[0-9+\- ]{7,20}$/', $old['phone'])) {
            $errors[] = 'Phone may contain only digits, +, - and spaces (7 to 20 characters).';
        }
        if ($pwError = valid_password($password)) {
            $errors[] = $pwError;
        }
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }
    }

    if (!$errors) {
        try {
            // Role is NOT read from the form: new accounts are always students (Business Rule 14).
            $stmt = db()->prepare(
                "INSERT INTO users (student_id, full_name, email, password_hash, phone, role)
                 VALUES (?, ?, ?, ?, ?, 'student')"
            );
            $stmt->execute([
                $old['student_id'], $old['full_name'], $old['email'],
                password_hash($password, PASSWORD_DEFAULT),
                $old['phone'] === '' ? null : $old['phone'],
            ]);
            rate_hit('register', client_ip());
            flash('success', 'Account created. You can log in now.');
            redirect('login.php');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'That email or roll number is already registered.';
            } else {
                throw $e;
            }
        }
    }
}

page_header('Register', 'register');
?>
<div class="card narrow">
    <h1>Create your account</h1>
    <p class="muted">Only <strong>@<?= e(ALLOWED_EMAIL_DOMAIN) ?></strong> emails can register.</p>

    <?php foreach ($errors as $err): ?>
        <div class="flash flash-error"><?= e($err) ?></div>
    <?php endforeach; ?>

    <form method="post" data-once novalidate>
        <?= once_field() ?>
        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" maxlength="100" required>

        <label for="student_id">Roll number</label>
        <input type="text" id="student_id" name="student_id" value="<?= e($old['student_id']) ?>" maxlength="20" placeholder="bcsf24m035" required>

        <label for="email">University email</label>
        <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" maxlength="150"
               placeholder="bcsf24m035@<?= e(ALLOWED_EMAIL_DOMAIN) ?>" data-domain="<?= e(ALLOWED_EMAIL_DOMAIN) ?>" required>

        <label for="phone">Phone <span class="muted">(optional)</span></label>
        <input type="text" id="phone" name="phone" value="<?= e($old['phone']) ?>" maxlength="20">

        <label for="password">Password</label>
        <input type="password" id="password" name="password" minlength="8" data-strength required>
        <div class="help">At least 8 characters with a letter and a number.</div>

        <label for="confirm_password">Confirm password</label>
        <input type="password" id="confirm_password" name="confirm_password" required>

        <p><button class="btn" type="submit">Register</button></p>
    </form>
    <p class="small">Already registered? <a href="<?= e(url('login.php')) ?>">Log in</a></p>
</div>
<?php page_footer();
