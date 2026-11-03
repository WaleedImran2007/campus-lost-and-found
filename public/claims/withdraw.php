<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$u = requireLogin();
require_csrf('claims/my.php');

[$ok, $msg] = withdraw_claim(post_int('claim_id'), (int) $u['user_id']);
flash($ok ? 'success' : 'error', $msg);
redirect('claims/my.php');
