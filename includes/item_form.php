<?php
/* Shared by items/create.php and items/edit.php: validation + the HTML form. */

/** Validate the listing fields. Returns [cleanData, errors]. */
function validate_item_input(bool $isNew): array
{
    $errors = [];
    $d = [
        'report_type'   => post('report_type'),
        'title'         => post('title'),
        'category_id'   => post_int('category_id'),
        'location_id'   => post_int('location_id'),
        'description'   => post('description'),
        'incident_date' => post('incident_date'),
        'is_high_value' => isset($_POST['is_high_value']) ? 1 : 0,
        'hidden_detail' => post('hidden_detail'),
    ];

    if ($isNew && !in_array($d['report_type'], ['lost', 'found'], true)) {          // whitelist
        $errors[] = 'Choose whether the item is lost or found.';
    }
    if (str_len($d['title']) < 3 || str_len($d['title']) > 120) {
        $errors[] = 'Title must be 3 to 120 characters.';
    }
    $ok = db()->prepare('SELECT 1 FROM categories WHERE category_id = ? AND is_active = 1');
    $ok->execute([$d['category_id']]);
    if (!$ok->fetchColumn()) $errors[] = 'Choose a valid category.';

    $ok = db()->prepare('SELECT 1 FROM campus_locations WHERE location_id = ? AND is_active = 1');
    $ok->execute([$d['location_id']]);
    if (!$ok->fetchColumn()) $errors[] = 'Choose a valid location.';

    if (str_len($d['description']) < 10 || str_len($d['description']) > 2000) {
        $errors[] = 'Description must be 10 to 2000 characters.';
    }
    if (!valid_date($d['incident_date'])) {
        $errors[] = 'Enter a valid date.';
    } elseif ($d['incident_date'] > date('Y-m-d') || $d['incident_date'] < date('Y-m-d', strtotime('-1 year'))) {
        $errors[] = 'The date cannot be in the future or older than one year.';
    }
    if (str_len($d['hidden_detail']) > 500) {
        $errors[] = 'The private detail can be at most 500 characters.';
    }
    return [$d, $errors];
}

/** @param array $v current values, @param array $opts ['isEdit'=>bool,'images'=>array,'dupWarning'=>?string] */
function render_item_form(array $v, array $opts = []): void
{
    $isEdit = !empty($opts['isEdit']);
    $type   = $v['report_type'] ?? 'lost';
    ?>
    <form method="post" enctype="multipart/form-data" <?= $isEdit ? '' : 'data-once' ?>>
        <?= $isEdit ? csrf_field() : once_field() ?>
        <?php if (!empty($opts['dupWarning'])): ?>
            <input type="hidden" name="confirm_dup" value="1">
        <?php endif; ?>

        <?php if (!$isEdit): ?>
            <label>What happened?</label>
            <div class="radio-cards">
                <label><input type="radio" name="report_type" value="lost" <?= $type === 'lost' ? 'checked' : '' ?>><span>&#128546; I lost something</span></label>
                <label><input type="radio" name="report_type" value="found" <?= $type === 'found' ? 'checked' : '' ?>><span>&#128578; I found something</span></label>
            </div>
        <?php endif; ?>

        <label for="title">Title</label>
        <input type="text" id="title" name="title" maxlength="120" value="<?= e($v['title'] ?? '') ?>" placeholder="e.g. Black leather wallet" required>

        <div class="grid grid-2">
            <div>
                <label for="category_id">Category</label>
                <select id="category_id" name="category_id" required>
                    <option value="">Choose...</option>
                    <?php foreach (active_categories() as $c): ?>
                        <option value="<?= (int) $c['category_id'] ?>" <?= (int) ($v['category_id'] ?? 0) === (int) $c['category_id'] ? 'selected' : '' ?>><?= e($c['category_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="location_id">Campus location</label>
                <select id="location_id" name="location_id" required>
                    <option value="">Choose...</option>
                    <?php foreach (active_locations() as $l): ?>
                        <option value="<?= (int) $l['location_id'] ?>" <?= (int) ($v['location_id'] ?? 0) === (int) $l['location_id'] ? 'selected' : '' ?>><?= e($l['location_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <label for="incident_date">Date lost / found</label>
        <input type="date" id="incident_date" name="incident_date" max="<?= date('Y-m-d') ?>" value="<?= e($v['incident_date'] ?? date('Y-m-d')) ?>" required>

        <label for="description">Public description</label>
        <textarea id="description" name="description" maxlength="2000" data-maxlen="2000" required
                  placeholder="Colour, brand, where exactly... Do NOT write secret details here, everyone can read this."><?= e($v['description'] ?? '') ?></textarea>

        <div data-found-only <?= $type === 'found' || $isEdit && $type === 'found' ? '' : 'style="display:none"' ?>>
            <label for="hidden_detail">Private hidden detail <span class="muted">(optional)</span></label>
            <textarea id="hidden_detail" name="hidden_detail" maxlength="500" data-maxlen="500" style="min-height:70px"
                      placeholder="Something only the real owner would know (serial number, lock-screen photo, what is inside...)"><?= e($v['hidden_detail'] ?? '') ?></textarea>
            <div class="help">Only you and custodians can see this. It is never shown to people who claim the item.</div>
            <label class="checkbox"><input type="checkbox" name="is_high_value" value="1" <?= !empty($v['is_high_value']) ? 'checked' : '' ?>>
                High-value item (phone, laptop, wallet with cash...) - extra ID check at handover</label>
        </div>

        <?php $existing = $opts['images'] ?? []; ?>
        <?php if ($existing): ?>
            <label>Current photos <span class="muted">(tick to remove)</span></label>
            <div class="previews">
                <?php foreach ($existing as $img): ?>
                    <label class="checkbox" style="flex-direction:column;margin:0">
                        <img src="<?= e(url('uploads/items/' . $img['image_path'])) ?>" alt="">
                        <span><input type="checkbox" name="remove_images[]" value="<?= (int) $img['image_id'] ?>"> remove</span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php $slots = MAX_IMAGES_PER_ITEM - count($existing); ?>
        <?php if ($slots > 0): ?>
            <label for="images">Photos <span class="muted">(up to <?= $slots ?> more, JPG/PNG/WebP/GIF, max 2 MB each)</span></label>
            <input type="file" id="images" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-preview data-max-files="<?= $slots ?>">
        <?php endif; ?>

        <p><button class="btn" type="submit"><?= $isEdit ? 'Save changes' : (!empty($opts['dupWarning']) ? 'Post anyway' : 'Post listing') ?></button>
           <a class="btn ghost" href="<?= e(url($isEdit ? 'items/view.php?id=' . (int) $opts['itemId'] : 'dashboard.php')) ?>">Cancel</a></p>
    </form>
    <?php
}
