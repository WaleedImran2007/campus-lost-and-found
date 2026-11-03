<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$u = requireActiveStudent();
$user_id = (int) $u['user_id'];
$id = get_int('item') ?: post_int('item_id');
$pdo = db();

$stmt = $pdo->prepare(
    'SELECT 
        i.item_id, i.title, i.report_type, i.item_status, i.reporter_id, c.category_name, l.location_name
    FROM items i JOIN categories c ON c.category_id = i.category_id JOIN campus_locations l ON l.location_id = i.location_id
    WHERE i.item_id = ?'
);

$stmt->execute([$id]);
$item = $stmt->fetch() ?: not_found('Listing');

// Business Rule 4 - checked here for a nice message, and AGAIN inside the transaction.

$problem = null;

if ($item['report_type'] !== 'found') {
    $problem = 'Claims are only allowed on found items. If you found a lost item, message the poster instead.';
}

elseif (!in_array($item['item_status'], ['open', 'claim_pending'], true)) {
    $problem = 'This item is no longer open for claims.';
} 

elseif ((int) $item['reporter_id'] === $user_id) {
    $problem = 'You cannot claim your own listing.';
}

else {
    $dup = $pdo->prepare('SELECT 1 FROM claims WHERE item_id = ? AND claimant_id = ?');

    $dup->execute([$id, $user_id]);

    if ($dup->fetchColumn()) {
        $problem = 'You have already submitted a claim for this item.';
    }
}

if ($problem) {
    flash('error', $problem);
    redirect('items/view.php?id=' . $id);
}

$errors = [];
$details = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $details = post('ownership_details');
    $files = collect_files('proof');

    if (!once_ok()) {
        $errors[] = 'This form was already submitted. Check "My claims".';
    }

    elseif (rate_blocked('claim', (string) $user_id)) {
        $errors[] = rate_message('claim');
    }

    if (str_len($details) < 20 || str_len($details) > 1500) {
        $errors[] = 'Describe how you can prove ownership (20 to 1500 characters).';
    }

    if (count($files) > 1) {
        $errors[] = 'Attach at most one proof file.';
    }

    foreach ($files as $f) {
        if ($err = check_upload($f, EVIDENCE_TYPES, MAX_PROOF_BYTES)) { $errors[] = $err; break; }
    }

    if (!$errors) {
        $stored = null;
        if ($files) $stored = store_upload($files[0], EVIDENCE_TYPES, EVIDENCE_DIR);   // saved OUTSIDE public/
        [$ok, $msg] = submit_claim($id, $u, $details, $stored);
        if ($ok) {
            rate_hit('claim', (string) $user_id);
            flash('success', $msg);
            redirect('claims/my.php');
        }
        if ($stored) @unlink(EVIDENCE_DIR . '/' . $stored);
        $errors[] = $msg;
    }
}

page_header('Claim item', 'claims');
?>
<div class="card medium">
    <h1>Claim: <?= e($item['title']) ?></h1>

    <p class="muted"><?= e($item['category_name']) ?> &middot; found at <?= e($item['location_name']) ?></p>
    
    <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>

    <div class="flash flash-info">Your statement and proof are <strong>private</strong>: only custodians and admins can read them.
        The person who found the item cannot see them.</div>

    <form method="post" enctype="multipart/form-data" data-once>
        <?= once_field() ?><input type="hidden" name="item_id" value="<?= $id ?>">
        <label for="ownership_details">How can you prove it is yours?</label>
        <textarea id="ownership_details" name="ownership_details" maxlength="1500" data-maxlen="1500" required
                  placeholder="Describe unique marks, what is inside, serial number, when/where you lost it..."><?= e($details) ?></textarea>

        <label for="proof">Proof file <span class="muted">(optional: photo of the item, receipt, box... JPG/PNG/PDF, max 3 MB)</span></label>
        <input type="file" id="proof" name="proof" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf">

        <p><button class="btn" type="submit">Submit claim</button>
           <a class="btn ghost" href="<?= e(url('items/view.php?id=' . $id)) ?>">Cancel</a></p>
    </form>
</div>
<?php page_footer();
