<?php
date_default_timezone_set('Asia/Bangkok');

function rentalEnv($key, $default = '') {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        if ($isSecure) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Load Composer autoload
require_once dirname(__DIR__) . '/vendor/autoload.php';

$host = rentalEnv('RENTAL_DB_HOST', 'localhost');
$username = rentalEnv('RENTAL_DB_USER', 'root');
$password = rentalEnv('RENTAL_DB_PASSWORD', '');
$database = rentalEnv('RENTAL_DB_NAME', 'rental_db');
$base_url = rentalEnv('RENTAL_BASE_URL', '/Rental/');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("SET time_zone = '+07:00'");
} catch(PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Database connection failed.');
}

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    error_log('MySQLi connection failed: ' . $conn->connect_error);
    http_response_code(500);
    die('Database connection failed.');
}
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+07:00'");

define('BASE_PATH', dirname(__DIR__) . '/');
define('BASE_URL', $base_url);
define('UPLOAD_PATH', BASE_PATH . 'assets/images/');
define('LOGO_PATH', UPLOAD_PATH . 'logo/');
?>
