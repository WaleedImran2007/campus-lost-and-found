<?php
/*
 * Business logic that changes several rows at once.
 * Every function here:
 *   1. runs inside ONE transaction,
 *   2. locks the item row first (SELECT ... FOR UPDATE) so two people cannot race,
 *   3. changes a status with  UPDATE ... WHERE status = <expected old value>
 *      and treats "0 rows changed" as an out-of-date request,
 *   4. writes the audit rows in the same transaction.
 * Each returns [bool $ok, string $message].
 */

class WorkflowException extends RuntimeException
{
}

function in_transaction(callable $fn): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $msg = $fn($pdo);
        $pdo->commit();
        return [true, (string) $msg];
    } catch (WorkflowException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [false, $e->getMessage()];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($e->getMessage());
        return [false, 'Something went wrong. Please try again.'];
    }
}

function fail(string $msg): never
{
    throw new WorkflowException($msg);
}

function lock_item(int $itemId): array
{
    $stmt = db()->prepare('SELECT * FROM items WHERE item_id = ? FOR UPDATE');
    $stmt->execute([$itemId]);
    return $stmt->fetch() ?: fail('Item not found.');
}

/** claim_pending -> open when the last active claim is gone. One atomic statement. */
function reopen_if_no_active_claims(int $itemId): void
{
    $stmt = db()->prepare(
        "UPDATE items SET item_status = 'open'
         WHERE item_id = ? AND item_status = 'claim_pending'
           AND NOT EXISTS (SELECT 1 FROM claims WHERE item_id = ? AND claim_status IN ('pending','under_review'))"
    );
    $stmt->execute([$itemId, $itemId]);
}

function check_reason(string $reason, string $label = 'A written reason'): void
{
    $len = str_len($reason);
    if ($len < 5 || $len > 500) {
        fail("$label (5 to 500 characters) is required.");
    }
}

// =================================================================== CLAIMS
function submit_claim(int $itemId, array $user, string $details, ?string $proofFile): array
{
    return in_transaction(function (PDO $pdo) use ($itemId, $user, $details, $proofFile) {
        $item = lock_item($itemId);
        if ($item['report_type'] !== 'found') fail('Claims are only allowed on found items.');
        if (!in_array($item['item_status'], ['open', 'claim_pending'], true)) fail('This item is no longer open for claims.');
        if ((int) $item['reporter_id'] === (int) $user['user_id']) fail('You cannot claim your own listing.');

        try {
            $pdo->prepare('INSERT INTO claims (item_id, claimant_id, ownership_details) VALUES (?, ?, ?)')
                ->execute([$itemId, $user['user_id'], $details]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') fail('You have already submitted a claim for this item.');
            throw $e;
        }
        $claimId = (int) $pdo->lastInsertId();

        if ($proofFile !== null) {
            $pdo->prepare("INSERT INTO private_item_evidence
                (item_id, claim_id, evidence_type, submitted_by, private_description, file_reference)
                VALUES (?, ?, 'claim_proof', ?, ?, ?)")
                ->execute([$itemId, $claimId, $user['user_id'], 'Proof file attached to claim', $proofFile]);
        }

        $pdo->prepare("UPDATE items SET item_status = 'claim_pending' WHERE item_id = ? AND item_status = 'open'")
            ->execute([$itemId]);

        notify((int) $item['reporter_id'], 'claim_submitted',
            'New ownership claim on your found item "' . $item['title'] . '".', $itemId);
        return 'Your claim was submitted. A custodian will review it.';
    });
}

function withdraw_claim(int $claimId, int $userId): array
{
    return in_transaction(function (PDO $pdo) use ($claimId, $userId) {
        $stmt = $pdo->prepare('SELECT item_id FROM claims WHERE claim_id = ? AND claimant_id = ?');
        $stmt->execute([$claimId, $userId]);
        $itemId = (int) ($stmt->fetchColumn() ?: fail('Claim not found.'));

        lock_item($itemId);
        $upd = $pdo->prepare("UPDATE claims SET claim_status = 'withdrawn'
                              WHERE claim_id = ? AND claimant_id = ? AND claim_status IN ('pending','under_review')");
        $upd->execute([$claimId, $userId]);
        if ($upd->rowCount() !== 1) fail('Only a pending claim can be withdrawn (it may have just been decided).');

        reopen_if_no_active_claims($itemId);
        return 'Claim withdrawn.';
    });
}

/** Opening a claim for review moves it pending -> under_review (staff only, not their own). */
function mark_claims_under_review(int $itemId, array $reviewer): void
{
    $stmt = db()->prepare(
        "UPDATE claims SET claim_status = 'under_review'
         WHERE item_id = ? AND claim_status = 'pending' AND claimant_id <> ?"
    );
    $stmt->execute([$itemId, $reviewer['user_id']]);
}

function check_reviewer(array $reviewer, array $claim, array $item): void
{
    if (!in_array($reviewer['role'], ['custodian', 'admin'], true)) fail('Only a custodian or admin can review claims.');
    if ((int) $reviewer['user_id'] === (int) $claim['claimant_id']) fail('You cannot review your own claim.');
    if ((int) $reviewer['user_id'] === (int) $item['reporter_id']) fail('You cannot review a claim on your own listing.');
}

function load_claim(int $claimId): array
{
    $stmt = db()->prepare('SELECT * FROM claims WHERE claim_id = ?');
    $stmt->execute([$claimId]);
    return $stmt->fetch() ?: fail('Claim not found.');
}

/** Approve one claim and reject all the others on the item (Section 3 of the project doc). */
function approve_claim(int $claimId, array $reviewer, string $reason): array
{
    return in_transaction(function (PDO $pdo) use ($claimId, $reviewer, $reason) {
        check_reason($reason);
        $claim = load_claim($claimId);
        $item  = lock_item((int) $claim['item_id']);                                  // step 1

        if ($item['report_type'] !== 'found' || !in_array($item['item_status'], ['open', 'claim_pending'], true)) {
            fail('This item is not in a state where a claim can be approved.');       // step 2
        }
        check_reviewer($reviewer, $claim, $item);

        $upd = $pdo->prepare("UPDATE claims SET claim_status = 'approved', reviewed_by = ?, review_reason = ?, reviewed_at = NOW()
                              WHERE claim_id = ? AND claim_status IN ('pending','under_review')");
        $upd->execute([$reviewer['user_id'], $reason, $claimId]);                     // step 3
        if ($upd->rowCount() !== 1) fail('This claim is out of date - someone else already decided it.');

        $others = $pdo->prepare("SELECT claim_id, claimant_id FROM claims
                                 WHERE item_id = ? AND claim_id <> ? AND claim_status IN ('pending','under_review')");
        $others->execute([$item['item_id'], $claimId]);
        $others = $others->fetchAll();

        $pdo->prepare("UPDATE claims SET claim_status = 'rejected', reviewed_by = ?, review_reason = 'Another claim was approved', reviewed_at = NOW()
                       WHERE item_id = ? AND claim_id <> ? AND claim_status IN ('pending','under_review')")   // step 4
            ->execute([$reviewer['user_id'], $item['item_id'], $claimId]);

        $st = $pdo->prepare("UPDATE items SET item_status = 'ready_for_handover'
                             WHERE item_id = ? AND item_status IN ('open','claim_pending')");  // step 5
        $st->execute([$item['item_id']]);
        if ($st->rowCount() !== 1) fail('The item changed while you were reviewing. Please reload.');

        audit_log((int) $reviewer['user_id'], 'claim_approved', 'claim', $claimId, $reason);
        audit_log((int) $reviewer['user_id'], 'item_status_changed', 'item', (int) $item['item_id'], $item['item_status'] . ' -> ready_for_handover');
        notify((int) $claim['claimant_id'], 'claim_approved',
            'Your claim on "' . $item['title'] . '" was approved. A custodian will schedule the handover.', (int) $item['item_id']);
        foreach ($others as $o) {
            audit_log((int) $reviewer['user_id'], 'claim_rejected', 'claim', (int) $o['claim_id'], 'Another claim was approved');
            notify((int) $o['claimant_id'], 'claim_rejected',
                'Your claim on "' . $item['title'] . '" was not successful: another claim was approved.', (int) $item['item_id']);
        }
        return 'Claim approved. ' . count($others) . ' other claim(s) were rejected automatically.';
    });
}

function reject_claim(int $claimId, array $reviewer, string $reason): array
{
    return in_transaction(function (PDO $pdo) use ($claimId, $reviewer, $reason) {
        check_reason($reason);
        $claim = load_claim($claimId);
        $item  = lock_item((int) $claim['item_id']);
        if (!in_array($item['item_status'], ['open', 'claim_pending'], true)) fail('This item is no longer under review.');
        check_reviewer($reviewer, $claim, $item);

        $upd = $pdo->prepare("UPDATE claims SET claim_status = 'rejected', reviewed_by = ?, review_reason = ?, reviewed_at = NOW()
                              WHERE claim_id = ? AND claim_status IN ('pending','under_review')");
        $upd->execute([$reviewer['user_id'], $reason, $claimId]);
        if ($upd->rowCount() !== 1) fail('This claim is out of date - someone else already decided it.');

        reopen_if_no_active_claims((int) $item['item_id']);
        audit_log((int) $reviewer['user_id'], 'claim_rejected', 'claim', $claimId, $reason);
        notify((int) $claim['claimant_id'], 'claim_rejected',
            'Your claim on "' . $item['title'] . '" was rejected. Reason: ' . $reason, (int) $item['item_id']);
        return 'Claim rejected.';
    });
}

/** Undo an approval before the handover is completed. Item goes back to open. */
function revoke_approval(int $claimId, array $reviewer, string $reason): array
{
    return in_transaction(function (PDO $pdo) use ($claimId, $reviewer, $reason) {
        check_reason($reason);
        $claim = load_claim($claimId);
        $item  = lock_item((int) $claim['item_id']);
        check_reviewer($reviewer, $claim, $item);
        if ($item['item_status'] !== 'ready_for_handover') fail('Only an item waiting for handover can have its approval revoked.');

        $upd = $pdo->prepare("UPDATE claims SET claim_status = 'rejected', reviewed_by = ?, review_reason = ?, reviewed_at = NOW()
                              WHERE claim_id = ? AND claim_status = 'approved'");
        $upd->execute([$reviewer['user_id'], 'Approval revoked: ' . $reason, $claimId]);
        if ($upd->rowCount() !== 1) fail('This claim is not approved any more.');

        $h = $pdo->prepare("UPDATE handovers SET handover_status = 'cancelled' WHERE claim_id = ? AND handover_status = 'scheduled'");
        $h->execute([$claimId]);
        if ($h->rowCount() === 1) {
            $hid = $pdo->prepare('SELECT handover_id FROM handovers WHERE claim_id = ?');
            $hid->execute([$claimId]);
            audit_log((int) $reviewer['user_id'], 'handover_cancelled', 'handover', (int) $hid->fetchColumn(), 'Approval revoked');
        }

        $st = $pdo->prepare("UPDATE items SET item_status = 'open' WHERE item_id = ? AND item_status = 'ready_for_handover'");
        $st->execute([$item['item_id']]);
        if ($st->rowCount() !== 1) fail('The item changed. Please reload.');

        audit_log((int) $reviewer['user_id'], 'approval_revoked', 'claim', $claimId, $reason);
        audit_log((int) $reviewer['user_id'], 'item_status_changed', 'item', (int) $item['item_id'], 'ready_for_handover -> open');
        notify((int) $claim['claimant_id'], 'claim_rejected',
            'The approval for "' . $item['title'] . '" was revoked. Reason: ' . $reason, (int) $item['item_id']);
        return 'Approval revoked. The item is open again.';
    });
}

// ================================================================ MESSAGES
/** Business Rule 10. Returns an error text, or null when sender may message recipient about this item. */
function message_allowed(array $item, int $senderId, int $recipientId): ?string
{
    if ($senderId === $recipientId) return 'You cannot message yourself.';
    if (in_array($item['item_status'], ['recovered', 'closed', 'removed'], true)) return 'Messaging is closed for this item.';

    $u = db()->prepare("SELECT role FROM users WHERE user_id = ? AND account_status <> 'deactivated'");
    $u->execute([$recipientId]);
    if (!$u->fetchColumn()) return 'That user does not exist.';

    $poster = (int) $item['reporter_id'];

    if ($item['report_type'] === 'lost') {
        if ($recipientId === $poster) return null;                       // anyone may message the poster
        $m = db()->prepare('SELECT 1 FROM messages WHERE item_id = ? AND sender_id = ? AND recipient_id = ? LIMIT 1');
        $m->execute([$item['item_id'], $recipientId, $senderId]);        // the poster may only answer
        return $m->fetchColumn() ? null : 'On a lost item you can only message the poster.';
    }

    // found item: poster <-> active claimants, and custodians/admins <-> poster/claimants
    $roleOf = function (int $uid) use ($item, $poster): ?string {
        $r = db()->prepare('SELECT role FROM users WHERE user_id = ?');
        $r->execute([$uid]);
        $role = $r->fetchColumn();
        if (in_array($role, ['custodian', 'admin'], true)) return 'staff';
        if ($uid === $poster) return 'poster';
        $c = db()->prepare("SELECT 1 FROM claims WHERE item_id = ? AND claimant_id = ? AND claim_status IN ('pending','under_review','approved')");
        $c->execute([$item['item_id'], $uid]);
        return $c->fetchColumn() ? 'claimant' : null;
    };
    $a = $roleOf($senderId);
    $b = $roleOf($recipientId);
    $ok = ($a === 'poster' && in_array($b, ['claimant', 'staff'], true))
       || ($b === 'poster' && in_array($a, ['claimant', 'staff'], true))
       || ($a === 'staff' && $b === 'claimant')
       || ($b === 'staff' && $a === 'claimant');
    return $ok ? null : 'On a found item, messages go only between the poster, people with an active claim, and custodians.';
}
// ================================================================== ITEMS
/** Poster closes their own listing. Lost: recovered|closed. Found: closed, only while open. */
function poster_set_status(int $itemId, int $userId, string $to): array
{
    return in_transaction(function (PDO $pdo) use ($itemId, $userId, $to) {
        $item = lock_item($itemId);
        if ((int) $item['reporter_id'] !== $userId) fail('Only the poster can do this.');
        if ($to === 'recovered' && $item['report_type'] !== 'lost') fail('Found items become recovered only through a handover.');
        if (!in_array($to, ['recovered', 'closed'], true)) fail('Invalid status.');

        $st = $pdo->prepare("UPDATE items SET item_status = ? WHERE item_id = ? AND reporter_id = ? AND item_status = 'open'");
        $st->execute([$to, $itemId, $userId]);
        if ($st->rowCount() !== 1) fail('Only an open listing can be changed.');

        audit_log($userId, 'item_status_changed', 'item', $itemId, "open -> $to");
        return 'Listing marked as ' . $to . '.';
    });
}

function staff_close_found_item(int $itemId, array $staff): array
{
    return in_transaction(function (PDO $pdo) use ($itemId, $staff) {
        $item = lock_item($itemId);
        if ($item['report_type'] !== 'found') fail('Only found items can be closed by staff.');
        $st = $pdo->prepare("UPDATE items SET item_status = 'closed' WHERE item_id = ? AND item_status = 'open'");
        $st->execute([$itemId]);
        if ($st->rowCount() !== 1) fail('Only an open item (with no active claims) can be closed.');
        audit_log((int) $staff['user_id'], 'item_status_changed', 'item', $itemId, 'open -> closed (by staff)');
        return 'Item closed.';
    });
}

/** Admin: any status -> removed. Active claims are withdrawn/rejected and handovers cancelled. */
