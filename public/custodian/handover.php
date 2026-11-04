<?php
/* Schedule, check the claimant's ID in person, and complete a handover (FR-16). */
require_once __DIR__ . '/../../includes/bootstrap.php';

$u   = requireRole(['custodian', 'admin']);
$id  = get_int('claim') ?: post_int('claim_id');
$pdo = db();

$stmt = $pdo->prepare(
    'SELECT c.claim_id, c.claim_status, c.claimant_id, u.full_name, u.student_id, u.email,
            i.item_id, i.title, i.item_status, i.is_high_value, i.reporter_id,
            (SELECT COUNT(*) FROM claims x WHERE x.item_id = i.item_id) AS total_claims,
            h.handover_id, h.handover_status, h.scheduled_at, h.completed_at, h.notes
     FROM claims c JOIN items i ON i.item_id = c.item_id JOIN users u ON u.user_id = c.claimant_id
     LEFT JOIN handovers h ON h.claim_id = c.claim_id
     WHERE c.claim_id = ?'
);
$stmt->execute([$id]);
$row = $stmt->fetch() ?: not_found('Claim');
$back = 'custodian/handover.php?claim=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf($back);
    if ($blocked = actionBlockReason($u)) {
        [$ok, $msg] = [false, $blocked];
    } elseif (post('action') === 'schedule') {
        [$ok, $msg] = schedule_handover($id, $u, post('scheduled_at'));
    } elseif (post('action') === 'complete') {
        [$ok, $msg] = complete_handover((int) $row['handover_id'], $u, post('notes'));
    } else {
        [$ok, $msg] = [false, 'Unknown action.'];
    }
    flash($ok ? 'success' : 'error', $msg);
    redirect($back);
}

$disputed = (int) $row['total_claims'] > 1;
$needsNotes = $row['is_high_value'] || $disputed;
$canAct = $row['claim_status'] === 'approved' && $row['item_status'] === 'ready_for_handover';

page_header('Handover', 'custodian');
?>
<p><a href="<?= e(url('custodian/review.php?item=' . $row['item_id'])) ?>">&larr; Back to claims</a></p>
<div class="card medium">
    <h1>Handover: <?= e($row['title']) ?></h1>
    <dl class="meta">
        <dt>Claimant</dt><dd><?= e($row['full_name']) ?> (<?= e($row['student_id'] ?? '-') ?>) &middot; <?= e($row['email']) ?></dd>
        <dt>Claim</dt><dd><?= badge($row['claim_status']) ?></dd>
        <dt>Item</dt><dd><?= badge($row['item_status']) ?> <?= $row['is_high_value'] ? '<span class="badge badge-claim_pending">High value</span>' : '' ?> <?= $disputed ? '<span class="badge badge-claim_pending">Disputed (' . (int) $row['total_claims'] . ' claims)</span>' : '' ?></dd>
        <dt>Handover</dt><dd><?= $row['handover_status'] ? badge($row['handover_status']) : '<span class="muted">not scheduled</span>' ?>
            <?= $row['scheduled_at'] ? ' ' . e(fmt_dt($row['scheduled_at'])) : '' ?></dd>
        <?php if ($row['notes']): ?><dt>ID-check notes</dt><dd><?= e($row['notes']) ?></dd><?php endif; ?>
    </dl>

    <?php if (!$canAct): ?>
        <div class="flash flash-info">This handover can no longer be changed.</div>
    <?php else: ?>
        <h2>1. Schedule</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="claim_id" value="<?= $id ?>"><input type="hidden" name="action" value="schedule">
            <label for="scheduled_at">Pick-up date and time</label>
            <input type="datetime-local" id="scheduled_at" name="scheduled_at" required
                   value="<?= e($row['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($row['scheduled_at'])) : '') ?>"
                   min="<?= date('Y-m-d\TH:i') ?>">
            <p><button class="btn" type="submit"><?= $row['handover_id'] ? 'Reschedule' : 'Schedule' ?></button></p>
        </form>

        <?php if ($row['handover_status'] === 'scheduled'): ?>
            <h2>2. Complete (claimant is present)</h2>
            <form method="post" data-confirm="Confirm that the claimant showed a valid ID and received the item?">
                <?= csrf_field() ?><input type="hidden" name="claim_id" value="<?= $id ?>"><input type="hidden" name="action" value="complete">
                <label for="notes">ID-check notes <?= $needsNotes ? '<span class="badge badge-claim_pending">required for this item</span>' : '<span class="muted">(optional)</span>' ?></label>
                <input type="text" id="notes" name="notes" maxlength="255" <?= $needsNotes ? 'required' : '' ?> placeholder="e.g. Checked student card BCSF24M035, matches claimant">
                <p><button class="btn success" type="submit">Confirm handover</button></p>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php page_footer();
