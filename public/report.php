<?php
/* Verified students report a listing, a message or a user (FR-21). */
require_once __DIR__ . '/../includes/bootstrap.php';

$u   = requireActiveStudent();
$uid = (int) $u['user_id'];
$pdo = db();

$itemId = get_int('item') ?: post_int('item_id');
$msgId  = get_int('message') ?: post_int('message_id');
$userId = get_int('user') ?: post_int('user_id');

$target = ['item_id' => null, 'message_id' => null, 'reported_user_id' => null];
$label  = '';

if ($itemId) {
    $s = $pdo->prepare("SELECT item_id, title, reporter_id FROM items WHERE item_id = ? AND item_status <> 'removed'");
    $s->execute([$itemId]);
    $row = $s->fetch() ?: not_found('Listing');
    if ((int) $row['reporter_id'] === $uid) { flash('error', 'You cannot report your own listing.'); redirect('items/view.php?id=' . $itemId); }
    $target['item_id'] = $itemId;
    $target['reported_user_id'] = (int) $row['reporter_id'];
    $label = 'listing "' . $row['title'] . '"';
} elseif ($msgId) {
    $s = $pdo->prepare("SELECT message_id, sender_id, recipient_id FROM messages WHERE message_id = ? AND message_status <> 'removed'");
    $s->execute([$msgId]);
    $row = $s->fetch() ?: not_found('Message');
    if ((int) $row['recipient_id'] !== $uid) not_found('Message');      // you can only report messages sent TO you
    $target['message_id'] = $msgId;
    $target['reported_user_id'] = (int) $row['sender_id'];
    $label = 'a message';
} elseif ($userId) {
    $s = $pdo->prepare("SELECT user_id, full_name FROM users WHERE user_id = ? AND account_status <> 'deactivated'");
    $s->execute([$userId]);
    $row = $s->fetch() ?: not_found('User');
    if ((int) $row['user_id'] === $uid) { flash('error', 'You cannot report yourself.'); redirect('dashboard.php'); }
    $target['reported_user_id'] = $userId;
    $label = 'user ' . $row['full_name'];
} else {
    not_found('Report target');
}

$reasons = ['spam' => 'Spam', 'fake_listing' => 'Fake listing', 'harassment' => 'Harassment', 'inappropriate' => 'Inappropriate content', 'other' => 'Other'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason  = post('reason');
    $details = post('details');
    if (!once_ok())                                      $errors[] = 'This report was already submitted.';
    elseif (rate_blocked('report', (string) $uid))       $errors[] = rate_message('report');
    if (!isset($reasons[$reason]))                       $errors[] = 'Choose a reason.';
    if (str_len($details) > 1000)                        $errors[] = 'Details can be at most 1000 characters.';
    if ($reason === 'other' && str_len($details) < 10)   $errors[] = 'Please explain the problem (at least 10 characters).';

    if (!$errors) {
        $pdo->prepare('INSERT INTO reports (reporter_id, reported_user_id, item_id, message_id, reason, details) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$uid, $target['reported_user_id'], $target['item_id'], $target['message_id'], $reason, $details === '' ? null : $details]);
        rate_hit('report', (string) $uid);
        flash('success', 'Thank you. An admin will review your report.');
        redirect('dashboard.php');
    }
}

page_header('Report', 'search');
?>
<div class="card narrow">
    <h1>Report <?= e($label) ?></h1>
    <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post" data-once>
        <?= once_field() ?>
        <input type="hidden" name="item_id" value="<?= (int) $itemId ?>">
        <input type="hidden" name="message_id" value="<?= (int) $msgId ?>">
        <input type="hidden" name="user_id" value="<?= (int) $userId ?>">
        <label for="reason">Reason</label>
        <select id="reason" name="reason" required>
            <option value="">Choose...</option>
            <?php foreach ($reasons as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
        </select>
        <label for="details">Details <span class="muted">(optional)</span></label>
        <textarea id="details" name="details" maxlength="1000" data-maxlen="1000"></textarea>
        <p><button class="btn danger" type="submit">Submit report</button> <a class="btn ghost" href="<?= e(url('dashboard.php')) ?>">Cancel</a></p>
    </form>
</div>
<?php page_footer();
