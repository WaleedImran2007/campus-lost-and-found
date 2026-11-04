<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$u   = requireRole(['custodian', 'admin']);
$pdo = db();

$pending = $pdo->query(
    "SELECT i.item_id, i.title, i.is_high_value, COUNT(c.claim_id) AS n, MIN(c.created_at) AS oldest
     FROM items i 
     JOIN claims c ON c.item_id = i.item_id AND c.claim_status IN ('pending','under_review')
     WHERE i.item_status IN ('open','claim_pending')
     GROUP BY i.item_id, i.title, i.is_high_value 
     ORDER BY oldest"
)->fetchAll();

$ready = $pdo->query(
    "SELECT i.item_id, i.title, i.is_high_value, c.claim_id, u.full_name AS claimant, h.handover_id, h.scheduled_at
     FROM items i
     JOIN claims c ON c.item_id = i.item_id AND c.claim_status = 'approved'
     JOIN users u ON u.user_id = c.claimant_id
     LEFT JOIN handovers h ON h.claim_id = c.claim_id AND h.handover_status = 'scheduled'
     WHERE i.item_status = 'ready_for_handover' 
     ORDER BY h.scheduled_at IS NULL DESC, h.scheduled_at"
)->fetchAll();

$done = $pdo->query(
    "SELECT i.item_id, i.title, h.completed_at, u.full_name AS claimant
     FROM handovers h 
     JOIN claims c ON c.claim_id = h.claim_id 
     JOIN items i ON i.item_id = c.item_id 
     JOIN users u ON u.user_id = c.claimant_id
     WHERE h.handover_status = 'completed' 
     ORDER BY h.completed_at DESC 
     LIMIT 8"
)->fetchAll();

page_header('Custodian dashboard', 'custodian');
?>
<h1>Custodian dashboard</h1>

<div class="card table-wrap">
    <h2>Claims waiting for review</h2>
    <?php if (!$pending): ?>
        <div class="empty">Nothing to review right now.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Found item</th>
                    <th>Active claims</th>
                    <th>Oldest claim</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending as $r): ?>
                    <tr>
                        <td>
                            <?= e($r['title']) ?> 
                            <?= $r['is_high_value'] ? '<span class="badge badge-claim_pending">High value</span>' : '' ?>
                        </td>
                        <td><?= (int) $r['n'] ?></td>
                        <td class="small"><?= e(time_ago($r['oldest'])) ?></td>
                        <td><a class="btn small" href="<?= e(url('custodian/review.php?item=' . $r['item_id'])) ?>">Review</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card table-wrap">
    <h2>Approved - ready for handover</h2>
    <?php if (!$ready): ?>
        <div class="empty">No approved items waiting.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Claimant</th>
                    <th>Pick-up</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ready as $r): ?>
                    <tr>
                        <td>
                            <?= e($r['title']) ?> 
                            <?= $r['is_high_value'] ? '<span class="badge badge-claim_pending">High value</span>' : '' ?>
                        </td>
                        <td><?= e($r['claimant']) ?></td>
                        <td><?= $r['scheduled_at'] ? e(fmt_dt($r['scheduled_at'])) : '<span class="badge badge-pending">Not scheduled</span>' ?></td>
                        <td><a class="btn small" href="<?= e(url('custodian/handover.php?claim=' . $r['claim_id'])) ?>"><?= $r['scheduled_at'] ? 'Open' : 'Schedule' ?></a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card table-wrap">
    <h2>Recently completed handovers</h2>
    <?php if (!$done): ?>
        <div class="empty">None yet.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Handed to</th>
                    <th>When</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($done as $r): ?>
                    <tr>
                        <td><a href="<?= e(url('items/view.php?id=' . $r['item_id'])) ?>"><?= e($r['title']) ?></a></td>
                        <td><?= e($r['claimant']) ?></td>
                        <td><?= e(fmt_dt($r['completed_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php
page_footer();