<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$u = requireRole('student');

$stmt = db()->prepare(
    'SELECT c.claim_id, c.claim_status, c.review_reason, c.created_at, c.reviewed_at,
            i.item_id, i.title, i.item_status,
            h.handover_status, h.scheduled_at, h.completed_at
     FROM claims c
     JOIN items i ON i.item_id = c.item_id
     LEFT JOIN handovers h ON h.claim_id = c.claim_id
     WHERE c.claimant_id = ?
     ORDER BY c.created_at DESC'
);
$stmt->execute([$u['user_id']]);
$claims = $stmt->fetchAll();

page_header('My claims', 'claims');
?>
<h1>My claims</h1>
<?php if (!$claims): ?>
    <div class="card empty">You have not claimed anything yet. <a href="<?= e(url('items/index.php?type=found')) ?>">Browse found items</a></div>
<?php else: ?>
    <div class="card table-wrap">
        <table>
            <thead><tr><th>Item</th><th>Claim status</th><th>Details</th><th>Submitted</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($claims as $c): ?>
                <tr>
                    <td><a href="<?= e(url('items/view.php?id=' . $c['item_id'])) ?>"><?= e($c['title']) ?></a><br><?= badge($c['item_status']) ?></td>
                    <td><?= badge($c['claim_status']) ?></td>
                    <td class="small">
                        <?php if ($c['review_reason']): ?>Reviewer: <?= e($c['review_reason']) ?><br><?php endif; ?>
                        <?php if ($c['claim_status'] === 'approved' && $c['handover_status'] === 'scheduled'): ?>
                            <strong>Pick-up: <?= e(fmt_dt($c['scheduled_at'])) ?></strong> - bring your university ID.
                        <?php elseif ($c['claim_status'] === 'approved' && $c['handover_status'] === 'completed'): ?>
                            Handed over on <?= e(fmt_dt($c['completed_at'])) ?>
                        <?php elseif ($c['claim_status'] === 'approved'): ?>
                            Approved - waiting for the custodian to schedule the handover.
                        <?php endif; ?>
                    </td>
                    <td class="nowrap small"><?= e(fmt_dt($c['created_at'])) ?></td>
                    <td>
                        <?php if (in_array($c['claim_status'], ['pending', 'under_review'], true)): ?>
                            <form method="post" action="<?= e(url('claims/withdraw.php')) ?>" data-confirm="Withdraw this claim?">
                                <?= csrf_field() ?><input type="hidden" name="claim_id" value="<?= (int) $c['claim_id'] ?>">
                                <button class="btn ghost small" type="submit">Withdraw</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php page_footer();
