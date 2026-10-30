<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (isLoggedIn() && currentUser()) {
    redirect('dashboard.php');
}

$stats = db()->query(
    "SELECT
        (SELECT COUNT(*) FROM items WHERE item_status IN ('open','claim_pending')) AS active_items,
        (SELECT COUNT(*) FROM items WHERE item_status = 'recovered') AS recovered,
        (SELECT COUNT(*) FROM users WHERE account_status <> 'deactivated') AS members"
)->fetch();

page_header('Home');
?>
<section class="hero">
    <h1>Lost something on campus? Found something?</h1>
    <p>Post it, search it, claim it. Every return is checked by a custodian, so belongings go back to their real owners.</p>
    <div class="row">
        <a class="btn" href="<?= e(url('register.php')) ?>">Create account</a>
        <a class="btn ghost" href="<?= e(url('login.php')) ?>">Log in</a>
    </div>
</section>

<div class="grid grid-3">
    <div class="card stat"><b><?= (int) $stats['active_items'] ?></b><span>items waiting right now</span></div>
    <div class="card stat"><b><?= (int) $stats['recovered'] ?></b><span>items returned to owners</span></div>
    <div class="card stat"><b><?= (int) $stats['members'] ?></b><span>students &amp; staff</span></div>
</div>

<h2>How it works</h2>
<div class="grid grid-3">
    <div class="card"><h3>1. Report</h3><p>Post a lost or found item with location, date and photos. Finders can add a private detail (like a serial number) that only staff see.</p></div>
    <div class="card"><h3>2. Claim</h3><p>Owners claim a found item with a private statement and optional proof. A custodian compares all claims together.</p></div>
    <div class="card"><h3>3. Hand over</h3><p>After approval, a custodian schedules a pick-up and checks your university ID in person.</p></div>
</div>
<p class="muted small">Registration is open to <strong>@<?= e(ALLOWED_EMAIL_DOMAIN) ?></strong> email addresses only.</p>
<?php page_footer();
