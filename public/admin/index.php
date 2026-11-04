<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$u   = requireRole('admin');
$pdo = db();

// Optional FR-26: close found items nobody claimed after STALE_FOUND_DAYS days.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('admin/index.php');
    [$ok, $msg] = in_transaction(function (PDO $pdo) use ($u) {
        $ids = $pdo->query("SELECT item_id FROM items WHERE report_type = 'found' AND item_status = 'open'
                            AND created_at < NOW() - INTERVAL " . (int) STALE_FOUND_DAYS . " DAY FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $itemId) {
            $st = $pdo->prepare("UPDATE items SET item_status = 'closed' WHERE item_id = ? AND item_status = 'open'");
            $st->execute([$itemId]);
            if ($st->rowCount() === 1) audit_log((int) $u['user_id'], 'item_status_changed', 'item', (int) $itemId, 'open -> closed (unclaimed ' . STALE_FOUND_DAYS . ' days)');
        }
        return count($ids) . ' stale found item(s) closed.';
    });
    flash($ok ? 'success' : 'error', $msg);
    redirect('admin/index.php');
}

function count_by(string $sql): array
{
    return db()->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
}
$items   = count_by('SELECT item_status, COUNT(*) FROM items GROUP BY item_status');
$types   = count_by('SELECT report_type, COUNT(*) FROM items GROUP BY report_type');
$claims  = count_by('SELECT claim_status, COUNT(*) FROM claims GROUP BY claim_status');
$users   = count_by('SELECT role, COUNT(*) FROM users GROUP BY role');
$openReports = (int) $pdo->query("SELECT COUNT(*) FROM reports WHERE report_status IN ('open','under_review')")->fetchColumn();
$suspended   = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE account_status = 'suspended'")->fetchColumn();
$totalItems  = array_sum($items);
$recovered   = (int) ($items['recovered'] ?? 0);

page_header('Admin', 'admin');
?>
<h1>Admin dashboard</h1>
<?php admin_subnav('index.php'); ?>

<div class="grid grid-4">
    <div class="card stat"><b><?= $totalItems ?></b><span>total listings</span></div>
    <div class="card stat"><b><?= $recovered ?></b><span>recovered (<?= $totalItems ? round($recovered * 100 / $totalItems) : 0 ?>%)</span></div>
    <div class="card stat"><b><?= $openReports ?></b><span><a href="<?= e(url('admin/reports.php')) ?>">open reports</a></span></div>
    <div class="card stat"><b><?= $suspended ?></b><span>suspended users</span></div>
</div>

<div class="grid grid-3">
    <div class="card"><h2>Listings by status</h2>
        <?php foreach ($items as $k => $n): ?><div class="notif"><span><?= badge($k) ?></span><b><?= (int) $n ?></b></div><?php endforeach; ?>
        <?php if (!$items): ?><div class="empty">No data.</div><?php endif; ?></div>
    <div class="card"><h2>Claims by status</h2>
        <?php foreach ($claims as $k => $n): ?><div class="notif"><span><?= badge($k) ?></span><b><?= (int) $n ?></b></div><?php endforeach; ?>
        <?php if (!$claims): ?><div class="empty">No claims yet.</div><?php endif; ?></div>
    <div class="card"><h2>People &amp; types</h2>
        <?php foreach ($users as $k => $n): ?><div class="notif"><span><?= badge($k) ?></span><b><?= (int) $n ?></b></div><?php endforeach; ?>
        <?php foreach ($types as $k => $n): ?><div class="notif"><span><?= badge($k) ?> listings</span><b><?= (int) $n ?></b></div><?php endforeach; ?></div>
</div>

<div class="card">
    <h2>Housekeeping</h2>
    <form method="post" data-confirm="Close all open found items older than <?= (int) STALE_FOUND_DAYS ?> days?">
        <?= csrf_field() ?>
        <button class="btn ghost" type="submit">Close unclaimed found items older than <?= (int) STALE_FOUND_DAYS ?> days</button>
    </form>
</div>
<?php page_footer();
