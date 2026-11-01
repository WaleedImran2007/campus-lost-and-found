<?php

require_once __DIR__ . '/../../includes/bootstrap.php';

$u = requireLogin();
$pdo = db();

// Get filter values
$q = trim((string) ($_GET['q'] ?? ''));

$type = in_array($_GET['type'] ?? '', ['lost', 'found'], true)
    ? $_GET['type'] : '';

$status = in_array($_GET['status'] ?? '', ['open', 'claim_pending'], true)
    ? $_GET['status'] : '';

$cat = (int) ($_GET['category'] ?? 0);
$loc = (int) ($_GET['location'] ?? 0);

$from = valid_date((string) ($_GET['from'] ?? ''))
    ? $_GET['from'] : '';

$to = valid_date((string) ($_GET['to'] ?? ''))
    ? $_GET['to'] : '';


// SQL query
$sql = "
    SELECT i.*,
           c.category_name,
           l.location_name,

           (SELECT image_path
            FROM item_images im
            WHERE im.item_id = i.item_id
            ORDER BY im.image_id
            LIMIT 1) AS thumb

    FROM items i

    JOIN categories c ON c.category_id = i.category_id
    JOIN campus_locations l ON l.location_id = i.location_id

    WHERE i.item_status IN ('open', 'claim_pending')

    AND (? = '' OR i.title LIKE ? OR i.description LIKE ?)
    AND (? = '' OR i.report_type = ?)
    AND (? = '' OR i.item_status = ?)
    AND (? = 0 OR i.category_id = ?)
    AND (? = 0 OR i.location_id = ?)
    AND (? = '' OR i.incident_date >= ?)
    AND (? = '' OR i.incident_date <= ?)

    ORDER BY i.created_at DESC
";


// Execute query
$stmt = $pdo->prepare($sql);

$stmt->execute([
    $q, "%$q%", "%$q%",
    $type, $type,
    $status, $status,
    $cat, $cat,
    $loc, $loc,
    $from, $from,
    $to, $to
]);

$items = $stmt->fetchAll();


// Load page header (CSS and navigation)
page_header('Search items', 'search');

?>

<div class="row between">

    <h1>Search lost &amp; found items</h1>

    <?php if ($u['role'] === 'student'): ?>
        <a class="btn" href="<?= e(url('items/create.php')) ?>">
            + Report an item
        </a>
    <?php endif; ?>

</div>


<!-- Search Form -->

<form class="card" method="get">

    <div class="filters">

        <div class="wide">
            <label for="q">Keyword</label>
            <input
                type="search"
                id="q"
                name="q"
                value="<?= e($q) ?>"
                placeholder="wallet, bottle, keys..."
            >
        </div>


        <div>
            <label for="type">Type</label>

            <select id="type" name="type">
                <option value="">All</option>

                <option value="lost" <?= $type === 'lost' ? 'selected' : '' ?>>
                    Lost
                </option>

                <option value="found" <?= $type === 'found' ? 'selected' : '' ?>>
                    Found
                </option>
            </select>
        </div>


        <div>
            <label for="status">Status</label>

            <select id="status" name="status">
                <option value="">Open &amp; pending</option>

                <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>
                    Open
                </option>

                <option value="claim_pending" <?= $status === 'claim_pending' ? 'selected' : '' ?>>
                    Claim pending
                </option>
            </select>
        </div>


        <div>
            <label for="category">Category</label>

            <select id="category" name="category">

                <option value="">All</option>

                <?php foreach (active_categories() as $c): ?>

                    <option
                        value="<?= (int) $c['category_id'] ?>"
                        <?= $cat === (int) $c['category_id'] ? 'selected' : '' ?>
                    >
                        <?= e($c['category_name']) ?>
                    </option>

                <?php endforeach; ?>

            </select>
        </div>


        <div>
            <label for="location">Location</label>

            <select id="location" name="location">

                <option value="">All</option>

                <?php foreach (active_locations() as $l): ?>

                    <option
                        value="<?= (int) $l['location_id'] ?>"
                        <?= $loc === (int) $l['location_id'] ? 'selected' : '' ?>
                    >
                        <?= e($l['location_name']) ?>
                    </option>

                <?php endforeach; ?>

            </select>
        </div>


        <div>
            <label for="from">From date</label>
            <input
                type="date"
                id="from"
                name="from"
                value="<?= e($from) ?>"
            >
        </div>


        <div>
            <label for="to">To date</label>
            <input
                type="date"
                id="to"
                name="to"
                value="<?= e($to) ?>"
            >
        </div>


        <div class="row">

            <button class="btn" type="submit">
                Search
            </button>

            <a class="btn ghost" href="<?= e(url('items/index.php')) ?>">
                Reset
            </a>

        </div>

    </div>

</form>


<!-- Search Results -->

<p class="muted">
    <?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?> found
</p>


<?php if (!$items): ?>

    <div class="card empty">
        Nothing matches your search. Try fewer filters.
    </div>

<?php else: ?>

    <div class="items">

        <?php foreach ($items as $r): ?>

            <article class="item-card">

                <div class="thumb">

                    <?php if ($r['thumb']): ?>

                        <img
                            src="<?= e(url('uploads/items/' . $r['thumb'])) ?>"
                            alt=""
                            loading="lazy"
                        >

                    <?php else: ?>

                        <?= $r['report_type'] === 'lost' ? '&#128546;' : '&#128230;' ?>

                    <?php endif; ?>

                </div>


                <div class="body">

                    <div class="row">

                        <?= badge($r['report_type']) ?>

                        <?= $r['item_status'] === 'claim_pending'
                            ? badge('claim_pending')
                            : '' ?>

                    </div>


                    <h3>
                        <a
                            class="stretch"
                            href="<?= e(url('items/view.php?id=' . $r['item_id'])) ?>"
                        >
                            <?= e($r['title']) ?>
                        </a>
                    </h3>


                    <span class="muted small">
                        <?= e($r['category_name']) ?>
                        &middot;
                        <?= e($r['location_name']) ?>
                    </span>


                    <span class="muted small">
                        <?= e(fmt_date($r['incident_date'])) ?>
                    </span>

                </div>

            </article>

        <?php endforeach; ?>

    </div>

<?php endif; ?>


<?php page_footer(); ?>