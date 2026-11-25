<?php
/**
 * Router for PHP's built-in web server. Used only to test the app on phones through an HTTPS tunnel
 * (Cloudflare Tunnel) without deploying it:
 *
 *   C:\xampp\php\php.exe -S 127.0.0.1:8090 -t C:\xampp\htdocs\cubetech C:\xampp\htdocs\cubetech\tools\phone-test-router.php
 *   cloudflared tunnel --url http://127.0.0.1:8090
 *
 * Why not tunnel Apache directly: tunnel visitors arrive from 127.0.0.1, so XAMPP's "local only" pages
 * (phpMyAdmin with the root account, the XAMPP dashboard) would become public. This server only serves
 * this app's folder, and only the app's own pages and assets (an allowlist). Everything else, including
 * .env, database.sql, README.md and includes/, gets a 404. The built-in server ignores .htaccess files,
 * so this allowlist does their job here.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '';

$allowed = '~^/(?:'
    . '|[a-z_]+\.php'                                // index.php, event.php, register.php, ticket.php, lookup.php, qr.php, setup.php
    . '|staff/?|staff/[a-z_]+\.php'                  // staff pages
    . '|assets/css/[a-z_-]+\.css|assets/js/[a-z_-]+\.js'
    . ')$~';

if (!preg_match($allowed, $path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    return true;
}
return false; // let the built-in server serve the allowed file normally
