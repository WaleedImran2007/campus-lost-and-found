<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$u   = requireLogin();
$uid = (int) $u['user_id'];

// One row per conversation = (item, other person). Positional "?" because $uid is used several times.
$stmt = db()->prepare(
    "SELECT m.item_id, MAX(i.title) AS title,
            IF(m.sender_id = ?, m.recipient_id, m.sender_id) AS other_id,
            MAX(m.message_id) AS last_id,
            SUM(m.recipient_id = ? AND m.message_status = 'sent') AS unread
     FROM messages m JOIN items i ON i.item_id = m.item_id
     WHERE (m.sender_id = ? OR m.recipient_id = ?) AND m.message_status <> 'removed'
     GROUP BY m.item_id, other_id
     ORDER BY last_id DESC LIMIT 50"
);
$stmt->execute([$uid, $uid, $uid, $uid]);
$threads = $stmt->fetchAll();

$last = db()->prepare('SELECT message_text, created_at, sender_id FROM messages WHERE message_id = ?');
$who  = db()->prepare('SELECT full_name FROM users WHERE user_id = ?');

page_header('Messages', 'messages');
?>
<h1>Messages</h1>
<div class="card">
    <?php if (!$threads): ?><div class="empty">No conversations yet. Open an item and use "Message poster".</div><?php endif; ?>
    <?php foreach ($threads as $t):
        $last->execute([$t['last_id']]); $lm = $last->fetch();
        $who->execute([$t['other_id']]); $name = $who->fetchColumn(); ?>
        <div class="notif <?= (int) $t['unread'] ? 'unread' : '' ?>">
            <span>
                <a href="<?= e(url('messages/thread.php?item=' . $t['item_id'] . '&with=' . $t['other_id'])) ?>"><?= e($name) ?></a>
                about <em><?= e($t['title']) ?></em>
                <?php if ((int) $t['unread']): ?><span class="pill"><?= (int) $t['unread'] ?></span><?php endif; ?><br>
                <span class="muted small"><?= (int) $lm['sender_id'] === $uid ? 'You: ' : '' ?><?= e(mb_strimwidth($lm['message_text'], 0, 90, '...')) ?></span>
            </span>
            <span class="muted small nowrap"><?= e(time_ago($lm['created_at'])) ?></span>
        </div>
    <?php endforeach; ?>
</div>
<?php page_footer();
