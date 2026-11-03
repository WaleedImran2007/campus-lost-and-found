<?php
/*
 * Serves PRIVATE evidence files (they live in /storage, outside the web root).
 * Business Rule 5:
 *   item_detail  -> the poster, custodians, admins   (never a claimant)
 *   claim_proof  -> that claimant, custodians, admins (never the poster or other claimants)
 * Every successful view is written to AUDIT_LOGS as "evidence_viewed".
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$u   = requireLogin();
$uid = (int) $u['user_id'];
$pdo = db();

function deny(): never
{
    http_response_code(403);
    page_header('Access denied');
    echo '<div class="card narrow"><h1>403 - Access denied</h1><p>You are not allowed to open this file.</p></div>';
    page_footer();
    exit;
}

$stmt = $pdo->prepare(
    'SELECT e.evidence_id, e.evidence_type, e.submitted_by, e.file_reference, e.item_id, i.reporter_id
     FROM private_item_evidence e JOIN items i ON i.item_id = e.item_id
     WHERE e.evidence_id = ?'
);
$stmt->execute([get_int('id')]);
$ev = $stmt->fetch();

if (!$ev || !$ev['file_reference']) {
    not_found('File');
}

$staff = isStaff($u);
$isPoster = (int) $ev['reporter_id'] === $uid;
$allowed = false;

if ($ev['evidence_type'] === 'claim_proof') {
    $allowed = ((int) $ev['submitted_by'] === $uid) || ($staff && !$isPoster);
} else {
    $c = $pdo->prepare('SELECT 1 FROM claims WHERE item_id = ? AND claimant_id = ?');
    $c->execute([$ev['item_id'], $uid]);
    $isClaimant = (bool) $c->fetchColumn();
    $allowed = !$isClaimant && ($isPoster || $staff);
}
if (!$allowed) {
    deny();
}

$path = EVIDENCE_DIR . '/' . basename($ev['file_reference']);        // basename() blocks "../" tricks
if (!is_file($path)) {
    not_found('File');
}

audit_log($uid, 'evidence_viewed', 'evidence', (int) $ev['evidence_id'], 'file on item #' . $ev['item_id']);

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="evidence-' . (int) $ev['evidence_id'] . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, no-store');
readfile($path);
