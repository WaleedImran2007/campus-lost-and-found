<?php
/*
 * Central settings.
 * On Ubuntu/Debian the MariaDB "root" user cannot be used from PHP, so the app has
 * its own database user. deploy/setup.sh creates it (database/create_user.sql).
 * Change DB_PASS here AND in database/create_user.sql if you want another password.
 */

date_default_timezone_set('Asia/Karachi');

// ---- Database -------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'campus_lost_found');
define('DB_USER', 'lostfound');
define('DB_PASS', 'your_database_password');

// ---- App ------------------------------------------------------------
define('APP_NAME', 'Campus Lost & Found');
define('ALLOWED_EMAIL_DOMAIN', 'pucit.edu.pk');   // registration is only for this domain

// If links break on your machine, set the URL path of the "public" folder here,
// e.g. '/campus-lost-found/public'. Leave empty to auto-detect.
define('BASE_URL_OVERRIDE', '');

// ---- Rate limits: [max hits, window in seconds] (Business Rule 11) ----
define('RATE_LIMITS', [
    'login_fail' => [5, 15 * 60],     // 5 failed logins / 15 min  (per email AND per IP)
    'register'   => [5, 3600],        // 5 registrations / hour / IP
    'listing'    => [5, 86400],       // 5 listings / day / user
    'claim'      => [10, 86400],      // 10 claims / day / user
    'message'    => [20, 3600],       // 20 messages / hour / user
    'report'     => [10, 86400],      // 10 reports / day / user
]);

// ---- Uploads ----------------------------------------------------------
define('MAX_IMAGES_PER_ITEM', 4);
define('MAX_IMAGE_BYTES', 2 * 1024 * 1024);       // 2 MB per listing image
define('MAX_PROOF_BYTES', 3 * 1024 * 1024);       // 3 MB per claim-proof file
define('IMAGE_DIR', dirname(__DIR__) . '/public/uploads/items'); // public (images only)
define('EVIDENCE_DIR', dirname(__DIR__) . '/storage/evidence');  // PRIVATE, outside public/

define('STALE_FOUND_DAYS', 90);
