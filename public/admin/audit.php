<?php
/* Read-only audit trail. Only admins can open it (FR-24). */
require_once __DIR__ . '/../../includes/bootstrap.php';

$u   = requireRole('admin');
$pdo = db();

$action = trim((string) ($_GET['action'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$where = '1=1'; $params = [];
if ($action !== '') { $where = 'a.action = ?'; $params[] = $action; }

$actions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$cnt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a WHERE $where"); $cnt->execute($params);
$pages = max(1, (int) ceil($cnt->fetchColumn() / $perPage));
$page = min($page, $pages);

$stmt = $pdo->prepare("SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.user_id = a.actor_id
                       WHERE $where ORDER BY a.log_id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage));
$stmt->execute($params);
$rows = $stmt->fetchAll();

page_header('Audit log', 'admin');
?>
<h1>Audit log</h1>
<?php admin_subnav('audit.php'); ?>
<form class="card row" method="get">
    <select name="action" style="max-width:280px"><option value="">All actions</option>
        <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= $a === $action ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select>
    <button class="btn" type="submit">Filter</button>
</form>
<div class="card table-wrap">
    <table><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Target</th><th>Details</th><th>IP</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
        <tr><td class="nowrap small"><?= e(fmt_dt($r['created_at'])) ?></td>
            <td><?= $r['actor_id'] ? e($r['full_name']) : '<span class="muted">system</span>' ?></td>
            <td><code><?= e($r['action']) ?></code></td>
            <td class="small"><?= e($r['target_type']) ?> #<?= (int) $r['target_id'] ?></td>
            <td class="small"><?= e($r['details'] ?? '') ?></td><td class="small muted"><?= e($r['ip_address'] ?? '') ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">No log entries.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<?php if ($pages > 1): ?><div class="pager"><?php for ($p = 1; $p <= $pages; $p++): ?><?= $p === $page ? '<span class="cur">' . $p . '</span>' : '<a href="' . e(page_link($p)) . '">' . $p . '</a>' ?><?php endfor; ?></div><?php endif; ?>
<?php page_footer();
