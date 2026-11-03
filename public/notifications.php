<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$u = requireLogin();
$uid = (int) $u['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('notifications.php');
    if (post('action') === 'read_all') {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$uid]);
    } elseif (post('action') === 'read') {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?')->execute([post_int('id'), $uid]);
    }
    redirect('notifications.php');
}

$stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, notification_id DESC LIMIT 60');
$stmt->execute([$uid]);
$list = $stmt->fetchAll();

page_header('Alerts', 'notifications');
?>
<div class="row between">
    <h1>Alerts</h1>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="read_all"><button class="btn ghost small" type="submit">Mark all read</button></form>
</div>
<div class="card">
    <?php if (!$list): ?><div class="empty">No alerts yet.</div><?php endif; ?>
    <?php foreach ($list as $n): ?>
        <div class="notif <?= $n['is_read'] ? '' : 'unread' ?>">
            <span>
                <?= e($n['notification_text']) ?>
                <?php if ($n['related_item_id']): ?> <a href="<?= e(url('items/view.php?id=' . $n['related_item_id'])) ?>">View</a><?php endif; ?>
                <br><span class="muted small"><?= e(time_ago($n['created_at'])) ?></span>
            </span>
            <?php if (!$n['is_read']): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int) $n['notification_id'] ?>">
                    <button class="btn ghost small" type="submit">Mark read</button></form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php page_footer();
