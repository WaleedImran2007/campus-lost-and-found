<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$u    = requireLogin();
$uid  = (int) $u['user_id'];
$itemId = get_int('item') ?: post_int('item_id');
$withId = get_int('with') ?: post_int('with_id');
$pdo  = db();

$stmt = $pdo->prepare('SELECT * FROM items WHERE item_id = ?');
$stmt->execute([$itemId]);
$item = $stmt->fetch() ?: not_found('Conversation');
if ($item['item_status'] === 'removed' && $u['role'] !== 'admin') not_found('Conversation');

$other = $pdo->prepare("SELECT user_id, full_name, role FROM users WHERE user_id = ? AND account_status <> 'deactivated'");
$other->execute([$withId]);
$other = $other->fetch() ?: not_found('Conversation');

$existing = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE item_id = ? AND ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?))');
$existing->execute([$itemId, $uid, $withId, $withId, $uid]);
$hasHistory = (int) $existing->fetchColumn() > 0;

$sendError = message_allowed($item, $uid, $withId);           // Business Rule 10
if (!$hasHistory && $sendError) {                             // cannot even open a new conversation
    flash('error', $sendError);
    redirect('items/view.php?id=' . $itemId);
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $text = post('message_text');
    if (!once_ok()) {
        $errors[] = 'That message was already sent.';
    } elseif ($reason = actionBlockReason($u)) {
        $errors[] = $reason;
    } elseif ($sendError) {
        $errors[] = $sendError;
    } elseif (rate_blocked('message', (string) $uid)) {
        $errors[] = rate_message('message');
    } elseif (str_len($text) < 1 || str_len($text) > 1000) {
        $errors[] = 'Message must be 1 to 1000 characters.';
    } else {
        $pdo->prepare('INSERT INTO messages (item_id, sender_id, recipient_id, message_text) VALUES (?, ?, ?, ?)')
            ->execute([$itemId, $uid, $withId, $text]);
        rate_hit('message', (string) $uid);
        notify($withId, 'new_message', $u['full_name'] . ' sent you a message about "' . $item['title'] . '".', $itemId);
        redirect('messages/thread.php?item=' . $itemId . '&with=' . $withId . '#end');
    }
}

// Mark what I received as read, then load the conversation.
$pdo->prepare("UPDATE messages SET message_status = 'read' WHERE item_id = ? AND sender_id = ? AND recipient_id = ? AND message_status = 'sent'")
    ->execute([$itemId, $withId, $uid]);

$msgs = $pdo->prepare(
    "SELECT message_id, sender_id, message_text, created_at FROM messages
     WHERE item_id = ? AND message_status <> 'removed'
       AND ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?))
     ORDER BY message_id"
);
$msgs->execute([$itemId, $uid, $withId, $withId, $uid]);
$msgs = $msgs->fetchAll();

page_header('Conversation', 'messages');
?>
<p><a href="<?= e(url('messages/inbox.php')) ?>">&larr; All messages</a></p>
<div class="card medium">
    <div class="row between">
        <div>
            <h1 style="margin:0"><?= e($other['full_name']) ?> <?= badge($other['role']) ?></h1>
            <span class="muted">about <a href="<?= e(url('items/view.php?id=' . $itemId)) ?>"><?= e($item['title']) ?></a> <?= badge($item['item_status']) ?></span>
        </div>
    </div>
    <hr style="border:0;border-top:1px solid var(--border)">
    <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>

    <div class="chat">
        <?php if (!$msgs): ?><div class="empty">No messages yet. Say hello.</div><?php endif; ?>
        <?php foreach ($msgs as $m): $mine = (int) $m['sender_id'] === $uid; ?>
            <div class="bubble <?= $mine ? 'mine' : '' ?>">
                <div style="white-space: pre-line"><?= e($m['message_text']) ?></div>
                <div class="when"><?= e(fmt_dt($m['created_at'])) ?>
                    <?php if (!$mine && $u['role'] === 'student' && !$u['is_suspended']): ?> &middot; <a href="<?= e(url('report.php?message=' . (int) $m['message_id'])) ?>">report</a><?php endif; ?></div>
            </div>
        <?php endforeach; ?>
        <span id="end"></span>
    </div>

    <?php if ($sendError): ?>
        <div class="flash flash-warning" style="margin-top:14px"><?= e($sendError) ?></div>
    <?php elseif ($u['is_suspended']): ?>
        <div class="flash flash-error" style="margin-top:14px">You cannot send messages while suspended.</div>
    <?php else: ?>
        <form method="post" data-once style="margin-top:14px">
            <?= once_field() ?><input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="with_id" value="<?= $withId ?>">
            <textarea name="message_text" maxlength="1000" data-maxlen="1000" style="min-height:80px" placeholder="Write a message... Never share passwords or send money." required></textarea>
            <p><button class="btn" type="submit">Send</button></p>
        </form>
    <?php endif; ?>
</div>
<?php page_footer();
