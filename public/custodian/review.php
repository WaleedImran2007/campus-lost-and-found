<?php
/* Review ALL claims on one found item together, with the private evidence (FR-13/14/15). */
require_once __DIR__ . '/../../includes/bootstrap.php';

$u   = requireRole(['custodian', 'admin']);
$uid = (int) $u['user_id'];
$id  = get_int('item') ?: post_int('item_id');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('custodian/review.php?item=' . $id);
    $claimId  = post_int('claim_id');
    $decision = post('decision');
    $reason   = post('reason');

    if ($reason_block = actionBlockReason($u)) {
        [$ok, $msg] = [false, $reason_block];
    } elseif ($decision === 'approve') {
        [$ok, $msg] = approve_claim($claimId, $u, $reason);
    } elseif ($decision === 'reject') {
        [$ok, $msg] = reject_claim($claimId, $u, $reason);
    } elseif ($decision === 'revoke') {
        [$ok, $msg] = revoke_approval($claimId, $u, $reason);
    } else {
        [$ok, $msg] = [false, 'Unknown action.'];
    }
    flash($ok ? 'success' : 'error', $msg);
    redirect('custodian/review.php?item=' . $id);
}

$stmt = $pdo->prepare(
    'SELECT i.*, c.category_name, l.location_name, p.full_name AS poster_name
     FROM items i JOIN categories c ON c.category_id = i.category_id JOIN campus_locations l ON l.location_id = i.location_id
     JOIN users p ON p.user_id = i.reporter_id WHERE i.item_id = ?'
);
$stmt->execute([$id]);
$item = $stmt->fetch() ?: not_found('Item');
if ($item['report_type'] !== 'found') {
    flash('error', 'Lost items have no claims - the poster closes them.');
    redirect('items/view.php?id=' . $id);
}
$ownListing = (int) $item['reporter_id'] === $uid;

// Opening the page moves pending claims to under_review (not for your own listing)
if (!$ownListing && in_array($item['item_status'], ['open', 'claim_pending'], true)) {
    mark_claims_under_review($id, $u);
}

$claims = $pdo->prepare(
    "SELECT c.*, u.full_name, u.email, u.phone, u.student_id,
            ev.evidence_id, ev.file_reference,
            h.handover_id, h.handover_status
     FROM claims c
     JOIN users u ON u.user_id = c.claimant_id
     LEFT JOIN private_item_evidence ev ON ev.claim_id = c.claim_id AND ev.evidence_type = 'claim_proof'
     LEFT JOIN handovers h ON h.claim_id = c.claim_id
     WHERE c.item_id = ? ORDER BY c.created_at"
);
$claims->execute([$id]);
$claims = $claims->fetchAll();

$det = $pdo->prepare("SELECT evidence_id, submitted_by, private_description FROM private_item_evidence
                      WHERE item_id = ? AND evidence_type = 'item_detail' AND private_description IS NOT NULL");
$det->execute([$id]);
$hidden = $det->fetch() ?: null;
if ($hidden && (int) $hidden['submitted_by'] !== $uid) {
    audit_log($uid, 'evidence_viewed', 'evidence', (int) $hidden['evidence_id'], 'hidden detail, claim review of item #' . $id);
}

$active = array_filter($claims, fn($c) => in_array($c['claim_status'], ['pending', 'under_review'], true));
$canDecide = !$ownListing && !$u['is_suspended'] && in_array($item['item_status'], ['open', 'claim_pending'], true);

page_header('Review claims', 'custodian');
?>
<p><a href="<?= e(url('custodian/index.php')) ?>">&larr; Custodian dashboard</a></p>
<div class="card">
    <div class="row"><?= badge($item['item_status']) ?> <?= $item['is_high_value'] ? '<span class="badge badge-claim_pending">High value</span>' : '' ?></div>
    <h1><a href="<?= e(url('items/view.php?id=' . $id)) ?>"><?= e($item['title']) ?></a></h1>
    <p class="muted"><?= e($item['category_name']) ?> &middot; <?= e($item['location_name']) ?> &middot; found <?= e(fmt_date($item['incident_date'])) ?> by <?= e($item['poster_name']) ?></p>
    <p style="white-space: pre-line"><?= e($item['description']) ?></p>
    <div class="private">
        <h3>&#128274; Finder's hidden detail</h3>
        <?= $hidden ? '<p style="white-space: pre-line">' . e($hidden['private_description']) . '</p>' : '<p class="muted">The finder did not add a hidden detail.</p>' ?>
        <p class="muted small">Compare it with each claimant's statement. Opening this page is recorded in the audit log.</p>
    </div>
    <?php if ($ownListing): ?><div class="flash flash-warning">This is your own listing, so you cannot review claims on it. Another custodian or admin must do it.</div><?php endif; ?>
</div>

<h2>Claims (<?= count($claims) ?>)</h2>
<?php if (!$claims): ?><div class="card empty">No claims on this item.</div><?php endif; ?>

<?php foreach ($claims as $c): $isActive = in_array($c['claim_status'], ['pending', 'under_review'], true); ?>
    <div class="card" <?= in_array($c['claim_status'], ['withdrawn', 'rejected'], true) ? 'style="opacity:.75"' : '' ?>>
        <div class="row between">
            <div><strong><?= e($c['full_name']) ?></strong> <span class="muted small"><?= e($c['student_id'] ?? '') ?> &middot; <?= e($c['email']) ?><?= $c['phone'] ? ' &middot; ' . e($c['phone']) : '' ?></span></div>
            <?= badge($c['claim_status']) ?>
        </div>
        <h3>Ownership statement</h3>
        <p style="white-space: pre-line"><?= e($c['ownership_details']) ?></p>
        <?php if ($c['file_reference']): ?>
            <p><a class="btn ghost small" target="_blank" href="<?= e(url('evidence.php?id=' . $c['evidence_id'])) ?>">&#128206; Open proof file</a>
               <span class="muted small">(logged in audit trail)</span></p>
        <?php endif; ?>
        <?php if ($c['review_reason']): ?><p class="small muted">Decision note: <?= e($c['review_reason']) ?></p><?php endif; ?>

        <?php if ($isActive && $canDecide): ?>
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><input type="hidden" name="claim_id" value="<?= (int) $c['claim_id'] ?>">
                <label>Written reason <span class="muted">(required for approve and reject)</span></label>
                <textarea name="reason" maxlength="500" style="min-height:70px" required></textarea>
                <p class="row">
                    <button class="btn success" type="submit" name="decision" value="approve">Approve</button>
                    <button class="btn danger" type="submit" name="decision" value="reject">Reject</button>
                </p>
            </form>
        <?php elseif ($c['claim_status'] === 'approved'): ?>
            <div class="row">
                <?php if ($item['item_status'] === 'ready_for_handover'): ?>
                    <a class="btn" href="<?= e(url('custodian/handover.php?claim=' . $c['claim_id'])) ?>">Schedule / complete handover</a>
                    <form method="post" data-confirm="Revoke this approval? The item will become open again.">
                        <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><input type="hidden" name="claim_id" value="<?= (int) $c['claim_id'] ?>"><input type="hidden" name="decision" value="revoke">
                        <input type="text" name="reason" placeholder="Reason for revoking (e.g. no-show)" maxlength="500" required style="width:260px">
                        <button class="btn ghost" type="submit">Revoke approval</button>
                    </form>
                <?php else: ?><span class="muted">Handed over - item recovered.</span><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php page_footer();
