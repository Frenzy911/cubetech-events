<?php
/**
 * config.php - loaded at the top of every page:   require_once __DIR__ . '/config.php';
 *
 *  1. Reads settings from .env (passwords and keys stay out of the source code)
 *  2. Connects to MySQL  ->  $conn  (mysqli)   e.g. $conn->query(...), $conn->prepare(...)
 *  3. Starts the session and loads the helper functions
 */

define('BASE_PATH', __DIR__);

/* ------------------------------------------------------------------ */
/* 1. Settings from .env                                               */
/* ------------------------------------------------------------------ */
function load_env_file(string $file): array
{
    $vars = [];
    if (!is_file($file)) {
        return $vars;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
            $value = substr($value, 1, -1);
        }
        $vars[$key] = $value;
    }
    return $vars;
}

// First run: create .env from .env.example with a fresh random APP_KEY.
if (!is_file(__DIR__ . '/.env') && is_file(__DIR__ . '/.env.example')) {
    $example = file_get_contents(__DIR__ . '/.env.example');
    $example = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . bin2hex(random_bytes(32)), $example);
    @file_put_contents(__DIR__ . '/.env', $example);
}

$ENV = load_env_file(__DIR__ . '/.env');

/** Read a setting from .env (empty values fall back to $default). */
function env(string $key, ?string $default = null): ?string
{
    $value = $GLOBALS['ENV'][$key] ?? null;
    return ($value === null || $value === '') ? $default : $value;
}

date_default_timezone_set('Asia/Manila'); // Cebu time (UTC+8)

define('APP_NAME', env('APP_NAME', 'CubeTech Events'));
define('APP_URL', rtrim(env('APP_URL', 'http://localhost/cubetech'), '/'));
define('APP_KEY', env('APP_KEY', ''));
define('APP_DEBUG', env('APP_DEBUG', 'false') === 'true');

if (strlen(APP_KEY) < 32 || APP_KEY === 'change-me-to-a-long-random-string') {
    http_response_code(500);
    exit('Set a long random APP_KEY in the .env file (see .env.example).');
}

/* ------------------------------------------------------------------ */
/* 2. Database connection  ->  $conn                                   */
/* ------------------------------------------------------------------ */
$DB_HOST = env('DB_HOST', 'localhost');
$DB_PORT = (int) env('DB_PORT', '3306');
$DB_NAME = env('DB_NAME', 'cubetech_events');
$DB_USER = env('DB_USER', 'root');
$DB_PASS = env('DB_PASS', '');

// Make mysqli throw exceptions on errors so nothing fails silently.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = null;
if (!defined('SKIP_DB')) {
    try {
        $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
        $conn->set_charset('utf8mb4');
        $conn->query("SET time_zone = '+08:00'"); // store and compare times in Cebu time
        $conn->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        $setupUrl = 'setup.php';
        if ($e->getCode() === 1049) {
            $msg = 'The database "' . htmlspecialchars($DB_NAME) . '" does not exist yet. '
                 . 'Open <a href="' . $setupUrl . '">setup.php</a> to install it, or import database.sql in phpMyAdmin.';
        } else {
            $msg = 'Cannot connect to MySQL. Start MySQL in the XAMPP Control Panel and check the DB_ settings in .env.'
                 . (APP_DEBUG ? '<br><small>' . htmlspecialchars($e->getMessage()) . '</small>' : '');
        }
        exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>Database error</title><div style="font-family:system-ui,sans-serif;max-width:560px;margin:3rem auto;padding:1.5rem;'
           . 'border:1px solid #fecaca;background:#fef2f2;border-radius:12px;color:#7f1d1d"><h1 style="margin-top:0">Database not ready</h1><p>'
           . $msg . '</p></div>');
    }
}

/* ------------------------------------------------------------------ */
/* 3. Session + helpers                                                */
/* ------------------------------------------------------------------ */
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode', '1');
    session_name('CTEVENTS');
    session_set_cookie_params([
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

require_once __DIR__ . '/includes/functions.php';
