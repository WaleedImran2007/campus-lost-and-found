<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$u = requireLogin();
$user_id = (int) $u['user_id'];
$pdo = db();

function scalar(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

$myListings = scalar("SELECT COUNT(*) FROM items WHERE reporter_id = ? AND item_status NOT IN ('removed')", [$user_id]);

$myOpen = scalar("SELECT COUNT(*) FROM items WHERE reporter_id = ? AND item_status IN ('open','claim_pending','ready_for_handover')", [$user_id]);

$myClaims = scalar("SELECT COUNT(*) FROM claims WHERE claimant_id = ? AND claim_status IN ('pending','under_review','approved')", [$user_id]);

$unreadMsg = unread_messages($user_id);
$unreadNotif = unread_notifications($user_id);

$recent = $pdo->prepare(
    "SELECT item_id, title, report_type, item_status, created_at FROM items
     WHERE reporter_id = ? AND item_status <> 'removed' ORDER BY created_at DESC LIMIT 5"
);

$recent->execute([$user_id]);

$recent = $recent->fetchAll();

$latest = $pdo->query(
    "SELECT 
        i.item_id, i.title, i.report_type, i.incident_date, c.category_name, l.location_name
    FROM items i JOIN categories c ON c.category_id = i.category_id JOIN campus_locations l ON l.location_id = i.location_id
    WHERE i.item_status IN ('open','claim_pending') ORDER BY i.created_at DESC LIMIT 5"
)->fetchAll();

page_header('Dashboard');
?>

<div class="row between">
    <div>
        <h1>Hi, <?= e($u['full_name']) ?></h1>
        <p class="muted">Signed in as <?= badge($u['role']) ?> <?= e($u['email']) ?></p>
    </div>
    <?php if ($u['role'] === 'student'): ?>
        <a class="btn" href="<?= e(url('items/create.php')) ?>">+ Report an item</a>
    <?php endif; ?>
</div>

<div class="grid grid-4">
    <?php if ($u['role'] === 'student'): ?>
        <div class="card stat">
            <b><?= $myOpen ?></b>
            <span>my active listings</span>
        </div>

        <div class="card stat">
            <b><?= $myClaims ?></b>
            <span>my active claims</span>
        </div>

    <?php else: ?>
        <div class="card stat">
            <b>
                <?= scalar("SELECT COUNT(DISTINCT item_id) FROM claims WHERE claim_status IN ('pending','under_review')") ?>
            </b>
            
            <span>items with claims to review</span>
        </div>

        <div class="card stat">
            <b>
                <?= scalar("SELECT COUNT(*) FROM items WHERE item_status = 'ready_for_handover'") ?>
            </b>

            <span>waiting for handover</span>
        </div>

    <?php endif; ?>
    <div class="card stat">
        <b><?= $unreadMsg ?></b>
        <span>
            <a href="<?= e(url('messages/inbox.php')) ?>">unread messages</a>
        </span>
    </div>

    <div class="card stat">
        <b><?= $unreadNotif ?></b>
        <span>
            <a href="<?= e(url('notifications.php')) ?>">new alerts</a>
        </span>
    </div>

</div>

<?php if (isStaff($u)): ?>
    <div class="card">
        <h2>Staff tools</h2>
        <div class="row">
            <a class="btn" href="<?= e(url('custodian/index.php')) ?>">Custodian dashboard</a>
            <?php if ($u['role'] === 'admin'): ?>
                <a class="btn ghost" href="<?= e(url('admin/index.php')) ?>">Admin dashboard</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-2">
    <div class="card">
        <h2>My recent listings</h2>
        <?php if (!$recent): ?>
            <div class="empty">You have not posted anything yet.</div>
        <?php else: ?>
            <?php foreach ($recent as $r): ?>
                <div class="notif">
                    <span><a href="<?= e(url('items/view.php?id=' . $r['item_id'])) ?>"><?= e($r['title']) ?></a>
                        <?= badge($r['report_type']) ?></span>
                    <?= badge($r['item_status']) ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Latest on campus</h2>
        <?php if (!$latest): ?>
            <div class="empty">No open items yet.</div>
        <?php else: ?>
            <?php foreach ($latest as $r): ?>
                <div class="notif">
                    <span><a href="<?= e(url('items/view.php?id=' . $r['item_id'])) ?>"><?= e($r['title']) ?></a>
                        <?= badge($r['report_type']) ?><br><span class="muted small"><?= e($r['location_name']) ?> &middot; <?= e(fmt_date($r['incident_date'])) ?></span></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <p><a href="<?= e(url('items/index.php')) ?>">Search all items &rarr;</a></p>
    </div>

</div>

<?php page_footer();