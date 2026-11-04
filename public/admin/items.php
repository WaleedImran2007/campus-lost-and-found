<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$u   = requireRole('admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('admin/items.php');
    [$ok, $msg] = remove_item(post_int('item_id'), $u, post('reason'));
    flash($ok ? 'success' : 'error', $msg);
    redirect('admin/items.php');
}

$q = trim((string) ($_GET['q'] ?? ''));
$status = in_array($_GET['status'] ?? '', ['open', 'claim_pending', 'ready_for_handover', 'recovered', 'closed', 'removed'], true) ? $_GET['status'] : '';
$where = ['1=1']; $params = [];
if ($q !== '') {
    if (ctype_digit($q)) { $where[] = 'i.item_id = ?'; $params[] = (int) $q; }
    else { $where[] = 'i.title LIKE ?'; $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%'; }
}
if ($status) { $where[] = 'i.item_status = ?'; $params[] = $status; }

$stmt = $pdo->prepare("SELECT i.item_id, i.title, i.report_type, i.item_status, i.created_at, u.full_name
                       FROM items i JOIN users u ON u.user_id = i.reporter_id
                       WHERE " . implode(' AND ', $where) . ' ORDER BY i.created_at DESC LIMIT 60');
$stmt->execute($params);
$list = $stmt->fetchAll();

page_header('Listings', 'admin');
?>
<h1>Listings</h1>
<?php admin_subnav('items.php'); ?>
<form class="card row" method="get">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Title or item #" style="max-width:260px">
    <select name="status" style="max-width:200px"><option value="">Any status</option>
        <?php foreach (['open', 'claim_pending', 'ready_for_handover', 'recovered', 'closed', 'removed'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select>
    <button class="btn" type="submit">Filter</button>
</form>
<div class="card table-wrap">
    <table><thead><tr><th>#</th><th>Listing</th><th>Poster</th><th>Status</th><th>Remove</th></tr></thead><tbody>
    <?php foreach ($list as $r): ?>
        <tr><td><?= (int) $r['item_id'] ?></td>
            <td><a href="<?= e(url('items/view.php?id=' . $r['item_id'])) ?>"><?= e($r['title']) ?></a> <?= badge($r['report_type']) ?></td>
            <td><?= e($r['full_name']) ?></td><td><?= badge($r['item_status']) ?></td>
            <td><?php if ($r['item_status'] !== 'removed'): ?>
                <form method="post" class="row" style="flex-wrap:nowrap" data-confirm="Remove this listing? This cannot be undone.">
                    <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= (int) $r['item_id'] ?>">
                    <input type="text" name="reason" placeholder="Reason" maxlength="500" required style="width:170px">
                    <button class="btn danger small" type="submit">Remove</button></form>
            <?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table>
</div>
<?php page_footer();
