<?php
/* Shared page frame: <head>, navigation bar, flash messages, footer. */

function nav_link(string $path, string $label, string $active, string $key, string $extra = ''): string
{
    $cls = $active === $key ? ' class="active"' : '';
    return '<a' . $cls . ' href="' . e(url($path)) . '">' . e($label) . $extra . '</a>';
}

function page_header(string $title, string $active = ''): void
{
    $u = currentUser();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
    <script>
        // apply saved theme before the page paints (avoids a white flash)
        (function () {
            try { var t = localStorage.getItem('theme'); if (t) document.documentElement.setAttribute('data-theme', t); } catch (e) {}
        })();
    </script>
</head>
<body>
<header class="topbar">
    <div class="container topbar-inner">
        <a class="brand" href="<?= e(url($u ? 'dashboard.php' : 'index.php')) ?>">
            <span class="brand-mark">&#128269;</span> <?= e(APP_NAME) ?>
        </a>
        <button class="nav-toggle" type="button" aria-label="Menu" data-nav-toggle>&#9776;</button>
        <nav class="nav" id="main-nav">
            <?php if ($u): ?>
                <?= nav_link('items/index.php', 'Search', $active, 'search') ?>
                <?php if ($u['role'] === 'student'): ?>
                    <?= nav_link('items/create.php', 'Report item', $active, 'create') ?>
                    <?= nav_link('claims/my.php', 'My claims', $active, 'claims') ?>
                <?php endif; ?>
                <?php if (isStaff($u)): ?>
                    <?= nav_link('custodian/index.php', 'Custodian', $active, 'custodian') ?>
                <?php endif; ?>
                <?php if ($u['role'] === 'admin'): ?>
                    <?= nav_link('admin/index.php', 'Admin', $active, 'admin') ?>
                <?php endif; ?>
                <?php $um = unread_messages((int) $u['user_id']); $un = unread_notifications((int) $u['user_id']); ?>
                <?= nav_link('messages/inbox.php', 'Messages', $active, 'messages', $um ? ' <span class="pill">' . $um . '</span>' : '') ?>
                <?= nav_link('notifications.php', 'Alerts', $active, 'notifications', $un ? ' <span class="pill">' . $un . '</span>' : '') ?>
                <?= nav_link('profile.php', $u['full_name'], $active, 'profile') ?>
                <a href="<?= e(url('logout.php')) ?>">Logout</a>
            <?php else: ?>
                <?= nav_link('login.php', 'Login', $active, 'login') ?>
                <?= nav_link('register.php', 'Register', $active, 'register') ?>
            <?php endif; ?>
            <button class="theme-toggle" type="button" title="Toggle dark mode" data-theme-toggle>&#9788;</button>
        </nav>
    </div>
</header>
<main class="container">
    <?= flash_render() ?>
    <?php if ($u && $u['is_suspended']): ?>
        <div class="flash flash-error">Your account is suspended - you can browse, but cannot post, claim, message or report.</div>
    <?php endif; ?>
<?php
}

function page_footer(): void
{
    ?>
</main>
<footer class="footer">
    <div class="container">
        <?= e(APP_NAME) ?> &middot; Web Technologies project &middot; PHP + MySQL
    </div>
</footer>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
<?php
}

/** Renders a standard "not found" page and stops. */
function not_found(string $what = 'Page'): never
{
    http_response_code(404);
    page_header('Not found');
    echo '<div class="card narrow"><h1>404</h1><p>' . e($what) . ' was not found or you cannot view it.</p>'
       . '<p><a class="btn" href="' . e(url('dashboard.php')) . '">Back to dashboard</a></p></div>';
    page_footer();
    exit;
}

/** Hidden "back" helper for pagination links keeping the current filters. */
function page_link(int $page): string
{
    $q = $_GET;
    $q['page'] = $page;
    return '?' . http_build_query($q);
}

/** Little tab bar used on all admin pages. */
function admin_subnav(string $active): void
{
    $tabs = ['index.php' => 'Overview', 'users.php' => 'Users', 'reports.php' => 'Reports', 'items.php' => 'Listings',
             'categories.php' => 'Categories & locations', 'audit.php' => 'Audit log'];
    echo '<div class="row" style="margin-bottom:18px">';
    foreach ($tabs as $file => $label) {
        $cls = $active === $file ? 'btn small' : 'btn ghost small';
        echo '<a class="' . $cls . '" href="' . e(url('admin/' . $file)) . '">' . e($label) . '</a>';
    }
    echo '</div>';
}
