<?php
// ============================================================
//  config.php — Database connection (PDO)
//  Edit these credentials to match your MySQL setup
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'saleshub');
define('DB_USER', 'root');       // ← change to your MySQL user
define('DB_PASS', '');           // ← change to your MySQL password
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'SalesHub');
define('APP_VERSION', '1.0.0');

function getPDO(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}