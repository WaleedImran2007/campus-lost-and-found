<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/item_form.php';

$u = requireActiveStudent();
$user_id = (int) $u['user_id'];
$errors = [];
$dupWarning = null;

$v = ['report_type' => ($_GET['type'] ?? '') === 'found' ? 'found' : 'lost', 'incident_date' => date('Y-m-d')];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$v, $errors] = validate_item_input(true);
    $files = collect_files('images');

    if (!once_ok()) {                                          // single-use form token
        $errors = ['This form was already submitted (or expired). Check "My recent listings" before posting again.'];
    } elseif (rate_blocked('listing', (string) $user_id)) {
        $errors[] = rate_message('listing');
    }

    if (count($files) > MAX_IMAGES_PER_ITEM) $errors[] = 'You can attach at most ' . MAX_IMAGES_PER_ITEM . ' images.';
    foreach ($files as $f) {
        if ($err = check_upload($f, IMAGE_TYPES, MAX_IMAGE_BYTES)) { $errors[] = $f['name'] . ': ' . $err; break; }
    }

    // Duplicate check (Business Rule 13)
    if (!$errors) {
        $stmt = db()->prepare(
            "SELECT title, (created_at >= NOW() - INTERVAL 1 DAY) AS within_day FROM items
             WHERE reporter_id = ? AND report_type = ? AND category_id = ? AND location_id = ? AND incident_date = ?
               AND item_status <> 'removed' AND created_at >= NOW() - INTERVAL 3 DAY"
        );
        
        $stmt->execute([$user_id, $v['report_type'], $v['category_id'], $v['location_id'], $v['incident_date']]);
        foreach ($stmt->fetchAll() as $row) {
            if ($row['within_day'] && mb_strtolower($row['title']) === mb_strtolower($v['title'])) {
                $errors[] = 'You already posted an identical listing in the last 24 hours.';
                break;
            }
            $dupWarning = 'You posted a similar listing ("' . $row['title'] . '") in the last 3 days. If this is a different item, choose your photos again and press "Post anyway".';
        }
        if ($dupWarning && post('confirm_dup') === '1') $dupWarning = null;
    }

    if (!$errors && !$dupWarning) {
        $saved = [];
        try {
            foreach ($files as $f) $saved[] = store_upload($f, IMAGE_TYPES, IMAGE_DIR);

            $pdo = db();
            $pdo->beginTransaction();
            $isFound = $v['report_type'] === 'found';
            $pdo->prepare('INSERT INTO items (reporter_id, report_type, title, category_id, location_id, description, incident_date, is_high_value)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$user_id, $v['report_type'], $v['title'], $v['category_id'], $v['location_id'], $v['description'],
                           $v['incident_date'], $isFound ? $v['is_high_value'] : 0]);
            $itemId = (int) $pdo->lastInsertId();

            foreach ($saved as $name) {
                $pdo->prepare('INSERT INTO item_images (item_id, image_path) VALUES (?, ?)')->execute([$itemId, $name]);
            }
            if ($isFound && $v['hidden_detail'] !== '') {
                $pdo->prepare("INSERT INTO private_item_evidence (item_id, evidence_type, submitted_by, private_description)
                               VALUES (?, 'item_detail', ?, ?)")->execute([$itemId, $user_id, $v['hidden_detail']]);
            }
            $pdo->commit();
            rate_hit('listing', (string) $user_id);

            flash('success', 'Your listing is live.');
            redirect('items/view.php?id=' . $itemId);
        } catch (PDOException $e) {
            if (db()->inTransaction()) db()->rollBack();
            foreach ($saved as $name) @unlink(IMAGE_DIR . '/' . $name);
            error_log($e->getMessage());
            $errors[] = 'Could not save the listing. Please try again.';
        }
    }
}

page_header('Report an item', 'create');
?>
<div class="card medium">
    <h1>Report a lost or found item</h1>
    <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>
    <?php if ($dupWarning): ?><div class="flash flash-warning"><?= e($dupWarning) ?></div><?php endif; ?>
    <?php render_item_form($v, ['dupWarning' => $dupWarning]); ?>
</div>
<?php page_footer();
