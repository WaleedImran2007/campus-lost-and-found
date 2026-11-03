<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$u   = requireRole('admin');
$uid = (int) $u['user_id'];
$pdo = db();

/** Who is the report about? An admin may not moderate a report about themselves (Business Rule 14). */
function report_is_about(array $r, int $uid): bool
{
    return (int) $r['reported_user_id'] === $uid || (int) $r['item_owner'] === $uid;
}

function finish_report(int $reportId, array $admin, string $status, string $notes): array
{
    return in_transaction(function (PDO $pdo) use ($reportId, $admin, $status, $notes) {
        $upd = $pdo->prepare("UPDATE reports SET report_status = ?, moderator_id = ?, resolution_notes = ?, resolved_at = NOW()
                              WHERE report_id = ? AND report_status IN ('open','under_review')");
        $upd->execute([$status, $admin['user_id'], $notes, $reportId]);
        if ($upd->rowCount() !== 1) fail('This report was already closed.');
        audit_log((int) $admin['user_id'], 'report_' . $status, 'report', $reportId, $notes);
        $rep = $pdo->prepare('SELECT reporter_id, item_id FROM reports WHERE report_id = ?');
        $rep->execute([$reportId]);
        $row = $rep->fetch();
        notify((int) $row['reporter_id'], 'report_update', 'Your report #' . $reportId . ' was ' . $status . '.', $row['item_id'] ? (int) $row['item_id'] : null);
        return 'Report ' . $status . '.';
    });
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('admin/reports.php');
    $rid = post_int('report_id');
    $s = $pdo->prepare("SELECT r.*, i.reporter_id AS item_owner FROM reports r LEFT JOIN items i ON i.item_id = r.item_id WHERE r.report_id = ?");
    $s->execute([$rid]);
    $r = $s->fetch();
    $notes = post('notes');
    $action = post('action');

    if (!$r) {
        [$ok, $msg] = [false, 'Report not found.'];
    } elseif (report_is_about($r, $uid)) {
        [$ok, $msg] = [false, 'You cannot moderate a report about yourself. Ask another admin.'];
    } elseif ($action === 'review') {
        $st = $pdo->prepare("UPDATE reports SET report_status = 'under_review', moderator_id = ? WHERE report_id = ? AND report_status = 'open'");
        $st->execute([$uid, $rid]);
        [$ok, $msg] = $st->rowCount() === 1 ? [true, 'Marked as under review.'] : [false, 'Report is not open.'];
    } elseif (str_len($notes) < 5 || str_len($notes) > 500) {
        [$ok, $msg] = [false, 'Write resolution notes (5 to 500 characters).'];
    } elseif ($action === 'dismiss') {
        [$ok, $msg] = finish_report($rid, $u, 'dismissed', $notes);
    } elseif ($action === 'remove') {
        if ($r['item_id']) {
            [$ok, $msg] = remove_item((int) $r['item_id'], $u, $notes);
        } elseif ($r['message_id']) {
            [$ok, $msg] = in_transaction(function (PDO $pdo) use ($r, $uid) {
                $st = $pdo->prepare("UPDATE messages SET message_status = 'removed' WHERE message_id = ? AND message_status IN ('sent','read')");
                $st->execute([$r['message_id']]);
                if ($st->rowCount() !== 1) fail('The message was already removed.');
                audit_log($uid, 'message_removed', 'message', (int) $r['message_id']);
                return 'Message removed.';
            });
        } else {
            [$ok, $msg] = [false, 'This report is about a user - suspend the user instead.'];
        }
        if ($ok) { [$ok2, $msg2] = finish_report($rid, $u, 'resolved', 'Content removed. ' . $notes); $msg .= ' ' . $msg2; }
    } elseif ($action === 'suspend') {
        $days = post('days') === '' ? null : max(1, min(365, post_int('days')));
        [$ok, $msg] = $r['reported_user_id'] ? suspend_user((int) $r['reported_user_id'], $u, $days, $notes) : [false, 'No user to suspend.'];
        if ($ok) { [$ok2, $msg2] = finish_report($rid, $u, 'resolved', 'User suspended. ' . $notes); $msg .= ' ' . $msg2; }
    } else {
        [$ok, $msg] = [false, 'Unknown action.'];
    }
    flash($ok ? 'success' : 'error', $msg);
    redirect('admin/reports.php');
}

$show = ($_GET['show'] ?? '') === 'all' ? 'all' : 'active';
$sql = "SELECT r.*, rep.full_name AS reporter_name, ru.full_name AS reported_name, ru.account_status AS reported_status,
               i.title AS item_title, i.reporter_id AS item_owner, m.message_text, m.message_status
        FROM reports r
        JOIN users rep ON rep.user_id = r.reporter_id
        LEFT JOIN users ru ON ru.user_id = r.reported_user_id
        LEFT JOIN items i ON i.item_id = r.item_id
        LEFT JOIN messages m ON m.message_id = r.message_id "
     . ($show === 'active' ? "WHERE r.report_status IN ('open','under_review') " : '') . 'ORDER BY r.created_at DESC LIMIT 50';
$reports = $pdo->query($sql)->fetchAll();

page_header('Reports', 'admin');
?>
<h1>Reports</h1>
<?php admin_subnav('reports.php'); ?>
<p><a href="?show=<?= $show === 'all' ? 'active' : 'all' ?>"><?= $show === 'all' ? 'Show only open reports' : 'Show all reports' ?></a></p>

<?php if (!$reports): ?><div class="card empty">No reports to show.</div><?php endif; ?>
<?php foreach ($reports as $r): $closed = in_array($r['report_status'], ['resolved', 'dismissed'], true); $self = report_is_about($r, $uid); ?>
    <div class="card">
        <div class="row between"><strong>Report #<?= (int) $r['report_id'] ?> &middot; <?= e(status_label($r['reason'])) ?></strong> <?= badge($r['report_status']) ?></div>
        <p class="muted small">By <?= e($r['reporter_name']) ?> &middot; <?= e(time_ago($r['created_at'])) ?></p>
        <dl class="meta">
            <?php if ($r['item_id']): ?><dt>Listing</dt><dd><a href="<?= e(url('items/view.php?id=' . $r['item_id'])) ?>"><?= e($r['item_title']) ?></a></dd><?php endif; ?>
            <?php if ($r['message_id']): ?><dt>Message</dt><dd style="white-space: pre-line"><?= e($r['message_text']) ?> <?= badge($r['message_status']) ?></dd><?php endif; ?>
            <?php if ($r['reported_user_id']): ?><dt>User</dt><dd><?= e($r['reported_name']) ?> <?= badge($r['reported_status']) ?></dd><?php endif; ?>
            <?php if ($r['details']): ?><dt>Details</dt><dd><?= e($r['details']) ?></dd><?php endif; ?>
            <?php if ($r['resolution_notes']): ?><dt>Resolution</dt><dd><?= e($r['resolution_notes']) ?></dd><?php endif; ?>
        </dl>
        <?php if ($self && !$closed): ?>
            <div class="flash flash-warning">This report is about you. Another admin must handle it.</div>
        <?php elseif (!$closed): ?>
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="report_id" value="<?= (int) $r['report_id'] ?>">
                <label>Resolution notes</label><input type="text" name="notes" maxlength="500" placeholder="What did you decide and why?">
                <div class="row" style="margin-top:10px">
                    <?php if ($r['report_status'] === 'open'): ?><button class="btn ghost small" name="action" value="review" type="submit">Start review</button><?php endif; ?>
                    <button class="btn ghost small" name="action" value="dismiss" type="submit">Dismiss</button>
                    <?php if ($r['item_id'] || $r['message_id']): ?><button class="btn danger small" name="action" value="remove" type="submit">Remove content</button><?php endif; ?>
                    <?php if ($r['reported_user_id']): ?>
                        <input type="number" name="days" min="1" max="365" placeholder="days" style="width:90px">
                        <button class="btn danger small" name="action" value="suspend" type="submit">Suspend user</button>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php page_footer();
