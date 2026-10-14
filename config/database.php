<?php
/*
 * One shared PDO connection.  Usage in any file:   $pdo = db();
 * Same options as in the course slides, plus:
 *  - FOUND_ROWS so rowCount() returns "rows matched" (needed for the
 *    "UPDATE ... WHERE status = expected" checks)
 *  - time_zone so MySQL NOW() matches PHP's Asia/Karachi time
 */
require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_FOUND_ROWS   => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+05:00'",
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            exit('<h2>Database connection failed.</h2><p>Is MySQL running in XAMPP and did you import '
               . '<code>database/schema.sql</code>? Check <code>config/config.php</code>.</p>');
        }
    }
    return $pdo;
}
