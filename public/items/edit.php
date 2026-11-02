<?php

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/item_form.php';

$u = requireActiveStudent();
$uid = (int) $u['user_id'];

$id = get_int('id') ?: post_int('item_id');

$pdo = db();


// 1. Get listing from database

$stmt = $pdo->prepare("
    SELECT *
    FROM items
    WHERE item_id = ? AND reporter_id = ?
");

$stmt->execute([$id, $uid]);

$item = $stmt->fetch();

if (!$item) {
    not_found('Listing');
}


// 2. Check whether listing can be edited

if ($item['item_status'] !== 'open') {

    flash('error', 'You can only edit a listing while it is open.');

    redirect('items/view.php?id=' . $id);
}


// 3. Get existing images

$stmt = $pdo->prepare("
    SELECT image_id, image_path
    FROM item_images
    WHERE item_id = ?
    ORDER BY image_id
");

$stmt->execute([$id]);

$images = $stmt->fetchAll();


// 4. Get private evidence

$stmt = $pdo->prepare("
    SELECT evidence_id, private_description
    FROM private_item_evidence
    WHERE item_id = ? AND evidence_type = 'item_detail'
");

$stmt->execute([$id]);

$detail = $stmt->fetch();


// Prepare form values

$v = $item;

$v['hidden_detail'] = $detail['private_description'] ?? '';

$errors = [];


// 5. Handle form submission

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Check CSRF token

    if (!csrf_ok()) {

        $errors[] = 'Security check failed. Please try again.';

    } else {

        // Validate form input

        [$input, $errors] = validate_item_input(false);

        $v = array_merge($item, $input);


        // Get images that user wants to remove

        $remove = array_map('intval', $_POST['remove_images'] ?? []);

        // Find images that will remain

        $keep = [];

        foreach ($images as $img) {

            if (!in_array((int) $img['image_id'], $remove, true)) {
                $keep[] = $img;
            }

        }


        // Get newly uploaded images

        $files = collect_files('images');


        // Check maximum image limit

        if (count($keep) + count($files) > MAX_IMAGES_PER_ITEM) {

            $errors[] = 'A listing can have at most ' . MAX_IMAGES_PER_ITEM . ' images.';

        }


        // Validate uploaded images

        foreach ($files as $file) {

            $error = check_upload($file, IMAGE_TYPES, MAX_IMAGE_BYTES);

            if ($error) {

                $errors[] = $file['name'] . ': ' . $error;

                break;

            }

        }


        // 6. Save changes if there are no errors

        if (empty($errors)) {

            $saved = [];

            try {

                // Store uploaded images

                foreach ($files as $file) {

                    $saved[] = store_upload($file, IMAGE_TYPES, IMAGE_DIR);

                }


                // Start database transaction

                $pdo->beginTransaction();


                // Update listing

                $stmt = $pdo->prepare("
                    UPDATE items
                    SET title = ?,
                        category_id = ?,
                        location_id = ?,
                        description = ?,
                        incident_date = ?,
                        is_high_value = ?
                    WHERE item_id = ?
                    AND reporter_id = ?
                    AND item_status = 'open'
                ");

                $highValue = 0;

                if ($item['report_type'] === 'found') {
                    $highValue = $input['is_high_value'];
                }

                $stmt->execute([
                    $input['title'],
                    $input['category_id'],
                    $input['location_id'],
                    $input['description'],
                    $input['incident_date'],
                    $highValue,
                    $id,
                    $uid
                ]);


                // Check whether listing was successfully updated

                if ($stmt->rowCount() !== 1) {

                    throw new WorkflowException(
                        'The listing is no longer open, so it cannot be edited.'
                    );

                }


                // 7. Delete removed images from database

                foreach ($images as $img) {

                    if (in_array((int) $img['image_id'], $remove, true)) {

                        $stmt = $pdo->prepare("
                            DELETE FROM item_images
                            WHERE image_id = ? AND item_id = ?
                        ");

                        $stmt->execute([
                            $img['image_id'],
                            $id
                        ]);

                    }

                }


                // 8. Insert newly uploaded images

                foreach ($saved as $filename) {

                    $stmt = $pdo->prepare("
                        INSERT INTO item_images (item_id, image_path)
                        VALUES (?, ?)
                    ");

                    $stmt->execute([
                        $id,
                        $filename
                    ]);

                }


                // 9. Update private evidence (only for found items)

                if ($item['report_type'] === 'found') {

                    $hiddenDetail = $input['hidden_detail'];

                    if ($detail && $hiddenDetail !== '') {

                        // Update existing evidence

                        $stmt = $pdo->prepare("
                            UPDATE private_item_evidence
                            SET private_description = ?
                            WHERE evidence_id = ?
                        ");

                        $stmt->execute([
                            $hiddenDetail,
                            $detail['evidence_id']
                        ]);

                    } elseif (!$detail && $hiddenDetail !== '') {

                        // Insert new evidence

                        $stmt = $pdo->prepare("
                            INSERT INTO private_item_evidence
                            (item_id, evidence_type, submitted_by, private_description)
                            VALUES (?, 'item_detail', ?, ?)
                        ");

                        $stmt->execute([
                            $id,
                            $uid,
                            $hiddenDetail
                        ]);

                    } elseif ($detail) {

                        // Clear existing evidence

                        $stmt = $pdo->prepare("
                            UPDATE private_item_evidence
                            SET private_description = NULL
                            WHERE evidence_id = ?
                        ");

                        $stmt->execute([
                            $detail['evidence_id']
                        ]);

                    }

                }


                // 10. Save all database changes

                $pdo->commit();


                // Delete removed image files from storage

                foreach ($images as $img) {

                    if (in_array((int) $img['image_id'], $remove, true)) {

                        @unlink(IMAGE_DIR . '/' . $img['image_path']);

                    }

                }


                // Success message

                flash('success', 'Listing updated.');

                redirect('items/view.php?id=' . $id);


            } catch (WorkflowException $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                // Delete newly uploaded files

                foreach ($saved as $filename) {
                    @unlink(IMAGE_DIR . '/' . $filename);
                }

                $errors[] = $e->getMessage();


            } catch (PDOException $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                foreach ($saved as $filename) {
                    @unlink(IMAGE_DIR . '/' . $filename);
                }

                error_log($e->getMessage());

                $errors[] = 'Could not save changes.';

            }

        }

    }

}


// 11. Display page

page_header('Edit listing', 'search');

?>

<div class="card medium">

    <h1>
        Edit listing <?= badge($item['report_type']) ?>
    </h1>


    <!-- Display errors -->

    <?php foreach ($errors as $error): ?>

        <div class="flash flash-error">
            <?= e($error) ?>
        </div>

    <?php endforeach; ?>


    <!-- Display edit form -->

    <?php

    render_item_form($v, [
        'isEdit' => true,
        'images' => $images,
        'itemId' => $id
    ]);

    ?>

</div>


<?php page_footer(); ?>