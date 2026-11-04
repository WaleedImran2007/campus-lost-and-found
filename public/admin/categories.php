<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$u   = requireRole('admin');
$pdo = db();

// kind => [table, id column, name column, max length]
$kinds = [
    'category' => ['categories', 'category_id', 'category_name', 60, 'Categories'],
    'location' => ['campus_locations', 'location_id', 'location_name', 80, 'Campus locations'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('admin/categories.php');
    $kind = post('kind');
    if (!isset($kinds[$kind])) {
        flash('error', 'Unknown list.');
        redirect('admin/categories.php');
    }
    [$table, $idCol, $nameCol, $max] = $kinds[$kind];       // names come from our own whitelist, never from the user

    if (post('action') === 'add') {
        $name = post('name');
        if (str_len($name) < 2 || str_len($name) > $max) {
            flash('error', "Name must be 2 to $max characters.");
        } else {
            try {
                $pdo->prepare("INSERT INTO $table ($nameCol) VALUES (?)")->execute([$name]);
                flash('success', 'Added.');
            } catch (PDOException $e) {
                flash('error', $e->getCode() === '23000' ? 'That name already exists.' : 'Could not add it.');
            }
        }
    } elseif (post('action') === 'toggle') {
        $pdo->prepare("UPDATE $table SET is_active = 1 - is_active WHERE $idCol = ?")->execute([post_int('id')]);
        flash('success', 'Updated. (Inactive entries disappear from new forms but stay on old listings.)');
    }
    redirect('admin/categories.php');
}

page_header('Categories & locations', 'admin');
?>
<h1>Categories &amp; locations</h1>
<?php admin_subnav('categories.php'); ?>
<div class="grid grid-2">
<?php foreach ($kinds as $kind => [$table, $idCol, $nameCol, $max, $title]):
    $rows = $pdo->query("SELECT $idCol AS id, $nameCol AS name, is_active FROM $table ORDER BY $nameCol")->fetchAll(); ?>
    <div class="card">
        <h2><?= e($title) ?></h2>
        <form method="post" class="row" style="flex-wrap:nowrap">
            <?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="action" value="add">
            <input type="text" name="name" maxlength="<?= (int) $max ?>" placeholder="New name" required><button class="btn" type="submit">Add</button>
        </form>
        <?php foreach ($rows as $r): ?>
            <div class="notif">
                <span <?= $r['is_active'] ? '' : 'class="muted"' ?>><?= e($r['name']) ?> <?= $r['is_active'] ? '' : '<span class="badge">inactive</span>' ?></span>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="btn ghost small" type="submit"><?= $r['is_active'] ? 'Disable' : 'Enable' ?></button></form>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
</div>
<?php page_footer();
