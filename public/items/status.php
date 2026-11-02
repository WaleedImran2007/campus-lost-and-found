<?php
/* POST only: poster closes / marks recovered, staff closes an unclaimed found item. */
require_once __DIR__ . '/../../includes/bootstrap.php';

$u  = requireLogin();
$id = post_int('item_id');
require_csrf('items/view.php?id=' . $id);

$to = post('to');
if ($to === 'staff_close') {
    requireRole(['custodian', 'admin']);
    [$ok, $msg] = staff_close_found_item($id, $u);
} else {
    if ($reason = actionBlockReason($u)) {
        flash('error', $reason);
        redirect('items/view.php?id=' . $id);
    }
    [$ok, $msg] = poster_set_status($id, (int) $u['user_id'], $to);
}
flash($ok ? 'success' : 'error', $msg);
redirect('items/view.php?id=' . $id);
