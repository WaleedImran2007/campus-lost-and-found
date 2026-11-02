<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$u = requireLogin();
$user_id = (int) $u['user_id'];
$id = get_int('id');
$pdo = db();

$stmt = $pdo->prepare(
    'SELECT 
        i.*, c.category_name, l.location_name, p.full_name AS poster_name
    FROM items i
    JOIN categories c ON c.category_id = i.category_id
    JOIN campus_locations l ON l.location_id = i.location_id
    JOIN users p ON p.user_id = i.reporter_id
    WHERE i.item_id = ?'
);

$stmt->execute([$id]);
$item = $stmt->fetch() ?: not_found('Listing');

$isPoster = (int) $item['reporter_id'] === $user_id;
$staff = isStaff($u);

$myClaim = null;
if ($u['role'] === 'student' && !$isPoster) {
    $c = $pdo->prepare('SELECT * FROM claims WHERE item_id = ? AND claimant_id = ?');
    $c->execute([$id, $user_id]);
    $myClaim = $c->fetch() ?: null;
}

// Not public (claimed/closed/removed) items are only visible to people involved in them.
$publicStatus = in_array($item['item_status'], ['open', 'claim_pending'], true);
if (!$publicStatus && !$isPoster && !$staff && !$myClaim) {
    not_found('Listing');
}
if ($item['item_status'] === 'removed' && $u['role'] !== 'admin') {
    not_found('Listing');
}

$images = $pdo->prepare('SELECT image_path FROM item_images WHERE item_id = ? ORDER BY image_id');
$images->execute([$id]);
$images = $images->fetchAll();

// Private hidden detail: poster + staff only (never claimants). Viewing by someone else is audited.
$hidden = null;
if (($isPoster || $staff) && !$myClaim) {
    $d = $pdo->prepare("SELECT evidence_id, submitted_by, private_description FROM private_item_evidence
                        WHERE item_id = ? AND evidence_type = 'item_detail' AND private_description IS NOT NULL");
    $d->execute([$id]);
    $hidden = $d->fetch() ?: null;
    if ($hidden && (int) $hidden['submitted_by'] !== $user_id) {
        audit_log($user_id, 'evidence_viewed', 'evidence', (int) $hidden['evidence_id'], 'hidden detail on item #' . $id);
    }
}

$claimCount = 0;
if ($isPoster || $staff) {
    $cc = $pdo->prepare("SELECT COUNT(*) FROM claims WHERE item_id = ? AND claim_status IN ('pending','under_review','approved')");
    $cc->execute([$id]);
    $claimCount = (int) $cc->fetchColumn();
}

// Possible matches (optional feature): opposite type, same category, ranked by same place + date closeness.
$matches = [];
if ($isPoster && $item['item_status'] === 'open') {
    $m = $pdo->prepare(
        "SELECT i.item_id, i.title, i.report_type, i.incident_date, l.location_name, (i.location_id = ?) AS same_place
         FROM items i JOIN campus_locations l ON l.location_id = i.location_id
         WHERE i.report_type <> ? AND i.category_id = ? AND i.item_status IN ('open','claim_pending') AND i.reporter_id <> ?
         ORDER BY same_place DESC, ABS(DATEDIFF(i.incident_date, ?)) ASC LIMIT 4"
    );
    $m->execute([$item['location_id'], $item['report_type'], $item['category_id'], $user_id, $item['incident_date']]);
    $matches = $m->fetchAll();
}

$canClaim = $u['role'] === 'student' && !$u['is_suspended'] && !$isPoster && !$myClaim && $item['report_type'] === 'found' && in_array($item['item_status'], ['open', 'claim_pending'], true);

$msgError = message_allowed($item, $user_id, (int) $item['reporter_id']);

$canMessagePoster = !$isPoster && !$u['is_suspended'] && $msgError === null;

page_header($item['title'], 'search');
?>

<p>
    <a href="<?= e(url('items/index.php')) ?>">&larr; Back to search</a>
</p>

<div class="grid grid-2" style="grid-template-columns: 2fr 1fr; align-items: start">
    <div>
        <div class="card">
            <div class="row"><?= badge($item['report_type']) ?> <?= badge($item['item_status']) ?>
                <?= $item['is_high_value'] ? '<span class="badge badge-claim_pending">High value</span>' : '' ?></div>
            <h1><?= e($item['title']) ?></h1>
            <?php if ($images): ?>
                <div class="gallery">
                    <?php foreach ($images as $img): ?>
                        <a href="<?= e(url('uploads/items/' . $img['image_path'])) ?>" target="_blank"><img src="<?= e(url('uploads/items/' . $img['image_path'])) ?>" alt="Photo of <?= e($item['title']) ?>"></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <dl class="meta">
                <dt>Category</dt><dd><?= e($item['category_name']) ?></dd>
                <dt>Location</dt><dd><?= e($item['location_name']) ?></dd>
                <dt><?= $item['report_type'] === 'lost' ? 'Lost on' : 'Found on' ?></dt><dd><?= e(fmt_date($item['incident_date'])) ?></dd>
                <dt>Posted by</dt><dd><?= e($item['poster_name']) ?> &middot; <?= e(time_ago($item['created_at'])) ?></dd>
            </dl>
            <h3>Description</h3>
            <p style="white-space: pre-line"><?= e($item['description']) ?></p>

            <?php if ($hidden): ?>
                <div class="private">
                    <h3>&#128274; Private hidden detail</h3>
                    <p style="white-space: pre-line"><?= e($hidden['private_description']) ?></p>
                    <p class="muted small">Visible only to the poster and custodians. Not shown to claimants.</p>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($matches): ?>
            <div class="card">
                <h2>Possible matches</h2>
                <?php foreach ($matches as $m): ?>
                    <div class="notif"><span><a href="<?= e(url('items/view.php?id=' . $m['item_id'])) ?>"><?= e($m['title']) ?></a>
                        <?= badge($m['report_type']) ?> <span class="muted small"><?= e($m['location_name']) ?> &middot; <?= e(fmt_date($m['incident_date'])) ?></span></span>
                        <?= $m['same_place'] ? '<span class="badge badge-open">same place</span>' : '' ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <aside>
        <div class="card">
            <h2>Actions</h2>
            <div class="grid" style="gap:10px">
            <?php if ($canClaim): ?>
                <a class="btn" href="<?= e(url('claims/create.php?item=' . $id)) ?>">This is mine - claim it</a>
            <?php endif; ?>

            <?php if ($myClaim): ?>
                <div class="flash flash-info" style="margin:0">Your claim: <?= badge($myClaim['claim_status']) ?>
                    <?php if ($myClaim['review_reason']): ?><br><span class="small">Reviewer note: <?= e($myClaim['review_reason']) ?></span><?php endif; ?></div>
                <a class="btn ghost" href="<?= e(url('claims/my.php')) ?>">Go to my claims</a>
            <?php endif; ?>

            <?php if ($canMessagePoster): ?>
                <a class="btn ghost" href="<?= e(url('messages/thread.php?item=' . $id . '&with=' . (int) $item['reporter_id'])) ?>">
                    <?= $item['report_type'] === 'lost' ? 'I found it - message poster' : 'Message poster' ?></a>
            <?php elseif (!$isPoster && $msgError && !$u['is_suspended'] && $item['report_type'] === 'found' && $u['role'] === 'student'): ?>
                <p class="muted small">To contact the finder, submit a claim first.</p>
            <?php endif; ?>

            <?php if ($isPoster && $item['item_status'] === 'open' && !$u['is_suspended']): ?>
                <a class="btn" href="<?= e(url('items/edit.php?id=' . $id)) ?>">Edit listing</a>
                <?php if ($item['report_type'] === 'lost'): ?>
                    <form method="post" action="<?= e(url('items/status.php')) ?>" data-confirm="Mark this item as recovered?">
                        <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><input type="hidden" name="to" value="recovered">
                        <button class="btn success" type="submit" style="width:100%">I got it back (recovered)</button></form>
                <?php endif; ?>
                <form method="post" action="<?= e(url('items/status.php')) ?>" data-confirm="Close this listing?">
                    <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><input type="hidden" name="to" value="closed">
                    <button class="btn ghost" type="submit" style="width:100%">Close listing</button></form>
            <?php endif; ?>

            <?php if ($staff && $item['report_type'] === 'found'): ?>
                <a class="btn" href="<?= e(url('custodian/review.php?item=' . $id)) ?>">Review claims (<?= $claimCount ?>)</a>
                <?php if ($item['item_status'] === 'open'): ?>
                    <form method="post" action="<?= e(url('items/status.php')) ?>" data-confirm="Close this found item as unclaimed?">
                        <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><input type="hidden" name="to" value="staff_close">
                        <button class="btn ghost" type="submit" style="width:100%">Close (unclaimed)</button></form>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($isPoster && $item['report_type'] === 'found' && $claimCount): ?>
                <p class="muted small"><?= $claimCount ?> active claim(s). A custodian is reviewing them.</p>
            <?php endif; ?>

            <?php if ($u['role'] === 'admin' && $item['item_status'] !== 'removed'): ?>
                <a class="btn danger" href="<?= e(url('admin/items.php?q=' . $id)) ?>">Moderate (admin)</a>
            <?php endif; ?>

            <?php if ($u['role'] === 'student' && !$isPoster && !$u['is_suspended']): ?>
                <a class="btn ghost small" href="<?= e(url('report.php?item=' . $id)) ?>">&#9873; Report this listing</a>
            <?php endif; ?>
            </div>
        </div>
    </aside>
</div>
<?php page_footer();
