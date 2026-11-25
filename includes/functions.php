<?php
/**
 * Shared helpers: database, security, formatting, events, auth, rate limiting, audit log.
 * Loaded by config.php.
 */

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/registrations.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/qrcode.php';

/* =====================================================================
 * Database helpers - always prepared statements on $conn (never string-joined SQL)
 * ===================================================================== */

function db(): mysqli
{
    global $conn;
    return $conn;
}

/** Prepare + bind + execute. Ints bind as "i", floats as "d", everything else as "s". */
function db_run(string $sql, array $params = []): mysqli_stmt
{
    $stmt = db()->prepare($sql);
    if ($params) {
        $params = array_values($params);
        $types = '';
        foreach ($params as $p) {
            $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
        }
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

function db_all(string $sql, array $params = []): array
{
    $result = db_run($sql, $params)->get_result();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function db_one(string $sql, array $params = []): ?array
{
    $result = db_run($sql, $params)->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    return $row ?: null;
}

function db_value(string $sql, array $params = [])
{
    $row = db_one($sql, $params);
    return $row ? reset($row) : null;
}

/** Runs INSERT/UPDATE/DELETE and returns the number of affected rows. */
function db_exec(string $sql, array $params = []): int
{
    return db_run($sql, $params)->affected_rows;
}

function db_insert(string $sql, array $params = []): int
{
    return (int) db_run($sql, $params)->insert_id;
}

/* =====================================================================
 * Output, URLs, redirects
 * ===================================================================== */

function h($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Web path of the app folder, e.g. "/cubetech" (detected automatically). */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $appDir = realpath(BASE_PATH);
    if ($docRoot && $appDir && stripos($appDir, $docRoot) === 0) {
        $base = str_replace('\\', '/', substr($appDir, strlen($docRoot)));
    } else {
        $base = (string) parse_url(APP_URL, PHP_URL_PATH);
    }
    $base = '/' . trim($base, '/');
    return $base = ($base === '/' ? '' : $base);
}

/** Root-relative URL inside the app, e.g. url('staff/login.php') -> /cubetech/staff/login.php */
function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

/** Absolute URL for emails, e.g. http://localhost/cubetech/ticket.php?... */
function abs_url(string $path = ''): string
{
    return APP_URL . '/' . ltrim($path, '/');
}

function redirect(string $to): never
{
    if (!preg_match('~^(https?:)?/~', $to)) {
        $to = url($to);
    }
    header('Location: ' . $to);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function wants_json(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function post_str(string $key, int $maxLen = 1000): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $maxLen) : '';
}

function get_str(string $key, int $maxLen = 200): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $maxLen) : '';
}

/* =====================================================================
 * CSRF + flash messages
 * ===================================================================== */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        if (wants_json()) {
            json_response(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
        }
        render_error_page(419, 'Session expired', 'Your session expired or the form was already used. Go back, reload the page and try again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

/* =====================================================================
 * Dates and times (always Cebu time, 12-hour clock)
 * ===================================================================== */

function now_str(): string
{
    return date('Y-m-d H:i:s');
}

function fmt_date(?string $dt): string
{
    return $dt ? date('D, M j, Y', strtotime($dt)) : '—';
}

function fmt_time(?string $dt): string
{
    return $dt ? date('g:i A', strtotime($dt)) : '—';
}

function fmt_datetime(?string $dt): string
{
    return $dt ? date('M j, Y g:i A', strtotime($dt)) : '—';
}

/** "6:00 PM – 10:00 PM", or "10:00 PM – Thu, Nov 26, 2:00 AM" when it ends on another day. */
function event_time_range(array $e): string
{
    $start = strtotime($e['starts_at']);
    $end = strtotime($e['ends_at']);
    if (date('Y-m-d', $start) === date('Y-m-d', $end)) {
        return date('g:i A', $start) . ' – ' . date('g:i A', $end);
    }
    return date('g:i A', $start) . ' – ' . date('D, M j, g:i A', $end);
}

/* =====================================================================
 * Events
 * ===================================================================== */

/** Event columns plus live registration counts. Append WHERE ... GROUP BY e.id */
const EVENT_SQL = "SELECT e.*,
        COALESCE(SUM(r.status IN ('confirmed','checked_in')), 0) AS taken,
        COALESCE(SUM(r.status = 'confirmed'), 0)  AS confirmed_count,
        COALESCE(SUM(r.status = 'checked_in'), 0) AS checked_in_count,
        COALESCE(SUM(r.status = 'waitlisted'), 0) AS waitlisted_count,
        COALESCE(SUM(r.status = 'cancelled'), 0)  AS cancelled_count
    FROM events e
    LEFT JOIN registrations r ON r.event_id = e.id";

function get_event(int $id): ?array
{
    return $id > 0 ? db_one(EVENT_SQL . ' WHERE e.id = ? GROUP BY e.id', [$id]) : null;
}

function event_is_ended(array $e): bool
{
    return time() >= strtotime($e['ends_at']);
}

function event_is_started(array $e): bool
{
    return time() >= strtotime($e['starts_at']);
}

/** True when today (Cebu time) is the event day. */
function event_is_today(array $e): bool
{
    $today = date('Y-m-d');
    return $today >= substr($e['starts_at'], 0, 10) && $today <= substr($e['ends_at'], 0, 10);
}

function event_spots_left(array $e): int
{
    return max(0, (int) $e['capacity'] - (int) ($e['taken'] ?? 0));
}

function event_is_full(array $e): bool
{
    return event_spots_left($e) <= 0;
}

/** Published and not ended yet. */
function event_open_for_registration(array $e): bool
{
    return $e['status'] === 'published' && !event_is_ended($e);
}

/* =====================================================================
 * Tickets
 * ===================================================================== */

const TICKET_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ'; // no 0/O, 1/I/L - easy to read aloud

/** 3-letter prefix from the event title, e.g. "Cebu Dev Meetup 2026" -> "CDM". */
function ticket_prefix(string $title): string
{
    $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $title));
    $prefix = '';
    foreach (preg_split('/\s+/', trim($title)) as $word) {
        if (preg_match('/^[A-Za-z]/', $word)) {
            $prefix .= strtoupper($word[0]);
        }
    }
    $prefix = substr($prefix . substr($letters, 1) . 'EVT', 0, 3);
    return $prefix;
}

/** Unguessable ticket code: prefix + 12 random characters (about 59 bits), e.g. CDM-7K3P-9QXW-M2RT. */
function generate_ticket_code(string $title): string
{
    $random = '';
    $max = strlen(TICKET_ALPHABET) - 1;
    for ($i = 0; $i < 12; $i++) {
        $random .= TICKET_ALPHABET[random_int(0, $max)];
    }
    return ticket_prefix($title) . '-' . substr($random, 0, 4) . '-' . substr($random, 4, 4) . '-' . substr($random, 8, 4);
}

/** Accepts "cdm 7k3p 9qxw m2rt", "CDM7K3P9QXWM2RT", ... and returns "CDM-7K3P-9QXW-M2RT". */
function normalize_ticket_code(string $input): string
{
    $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input));
    if (strlen($clean) === 15) {
        return substr($clean, 0, 3) . '-' . substr($clean, 3, 4) . '-' . substr($clean, 7, 4) . '-' . substr($clean, 11, 4);
    }
    return substr(strtoupper(trim($input)), 0, 40);
}

/** HMAC signature so a ticket page link can't be guessed from the ticket code alone. */
function ticket_sig(string $code): string
{
    return substr(hash_hmac('sha256', 'ticket|' . $code, APP_KEY), 0, 32);
}

function ticket_path(string $code): string
{
    return 'ticket.php?code=' . rawurlencode($code) . '&sig=' . ticket_sig($code);
}

function ticket_sig_valid(string $code, string $sig): bool
{
    return $code !== '' && $sig !== '' && hash_equals(ticket_sig($code), $sig);
}

/* =====================================================================
 * Validation
 * ===================================================================== */

/** Removes spaces/dashes; converts +639XXXXXXXXX to 09XXXXXXXXX. */
function normalize_mobile(string $mobile): string
{
    $m = preg_replace('/[\s\-().]/', '', trim($mobile));
    if (preg_match('/^\+?63(9\d{9})$/', $m, $match)) {
        $m = '0' . $match[1];
    }
    return $m;
}

function valid_mobile(string $mobile): bool
{
    return (bool) preg_match('/^09\d{9}$/', $mobile);
}

function valid_email(string $email): bool
{
    return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Returns field => error message for the attendee registration form. */
function validate_registration(array $v): array
{
    $errors = [];
    if ($v['full_name'] === '') {
        $errors['full_name'] = 'Please enter your full name.';
    } elseif (mb_strlen($v['full_name']) < 2) {
        $errors['full_name'] = 'Please enter your full name (at least 2 characters).';
    } elseif (mb_strlen($v['full_name']) > 120) {
        $errors['full_name'] = 'Name must be 120 characters or less.';
    }

    if ($v['email'] === '') {
        $errors['email'] = 'Please enter your email address.';
    } elseif (!valid_email($v['email'])) {
        $errors['email'] = 'Please enter a valid email address, for example juan@example.com.';
    }

    if ($v['mobile'] === '') {
        $errors['mobile'] = 'Please enter your mobile number.';
    } elseif (!valid_mobile($v['mobile'])) {
        $errors['mobile'] = 'Mobile number must be 11 digits and start with 09, for example 09171234567.';
    }
    return $errors;
}

function mask_email(string $email): string
{
    [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $visible = mb_substr($user, 0, min(2, mb_strlen($user)));
    return $visible . str_repeat('•', max(3, mb_strlen($user) - 2)) . '@' . $domain;
}

/* =====================================================================
 * Staff authentication + roles (checked on the server for every request)
 * ===================================================================== */

function current_staff(bool $refresh = false): ?array
{
    static $cache = false;
    if ($cache !== false && !$refresh) {
        return $cache;
    }
    $cache = null;
    if (!empty($_SESSION['staff_id']) && db() instanceof mysqli) {
        $row = db_one('SELECT id, name, username, role, is_active FROM staff_users WHERE id = ?', [(int) $_SESSION['staff_id']]);
        if ($row && (int) $row['is_active'] === 1) {
            $cache = $row;
        } else {
            unset($_SESSION['staff_id']);
        }
    }
    return $cache;
}

function is_organizer(?array $staff = null): bool
{
    $staff = $staff ?? current_staff();
    return $staff !== null && $staff['role'] === 'organizer';
}

function staff_home(array $staff): string
{
    return $staff['role'] === 'organizer' ? 'staff/index.php' : 'staff/checkin.php';
}

/** Any signed-in staff member. Signed-out visitors go to the sign-in page (or get 401 JSON). */
function require_staff(): array
{
    $staff = current_staff();
    if (!$staff) {
        if (wants_json()) {
            json_response(['ok' => false, 'error' => 'Please sign in again.', 'login' => url('staff/login.php')], 401);
        }
        flash('info', 'Please sign in to continue.');
        redirect('staff/login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? url('staff/')));
    }
    return $staff;
}

/** Organizers only. Door staff get a 403 page. */
function require_organizer(): array
{
    $staff = require_staff();
    if ($staff['role'] !== 'organizer') {
        if (wants_json()) {
            json_response(['ok' => false, 'error' => 'Only organizers can do this.'], 403);
        }
        render_error_page(403, 'Organizers only', 'Your account is for door check-in only. Ask an organizer if you need access to this page.', 'staff');
    }
    return $staff;
}

function role_label(string $role): string
{
    return $role === 'organizer' ? 'Organizer' : 'Door staff';
}

/* =====================================================================
 * Rate limiting (stored in MySQL so it works across browsers/devices)
 * ===================================================================== */

function rate_limited(string $bucket, string $ident, int $max, int $seconds): bool
{
    $count = (int) db_value(
        'SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND ident = ? AND created_at > NOW() - INTERVAL ? SECOND',
        [$bucket, $ident, $seconds]
    );
    return $count >= $max;
}

function rate_hit(string $bucket, string $ident): void
{
    db_exec('INSERT INTO rate_limits (bucket, ident) VALUES (?, ?)', [$bucket, $ident]);
    if (random_int(1, 50) === 1) {
        db_exec('DELETE FROM rate_limits WHERE created_at < NOW() - INTERVAL 1 DAY');
    }
}

/* =====================================================================
 * Audit log
 * ===================================================================== */

const AUDIT_ACTIONS = [
    'event_created'          => 'Event created',
    'event_updated'          => 'Event edited',
    'registration_cancelled' => 'Registration cancelled',
    'waitlist_promoted'      => 'Waitlist promotion',
    'check_in'               => 'Check-in',
    'check_in_undone'        => 'Check-in undone',
    'staff_created'          => 'Staff account created',
    'staff_updated'          => 'Staff account updated',
    'demo_reset'             => 'Demo data reset',
];

/** Records who did what. $actor = staff row, or null for an attendee (self-service). */
function audit(string $action, string $targetType, ?int $targetId, string $details, ?array $actor = null): void
{
    db_exec(
        'INSERT INTO audit_logs (staff_user_id, actor, action, target_type, target_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            $actor ? (int) $actor['id'] : null,
            $actor ? $actor['name'] : 'Attendee',
            $action,
            $targetType,
            $targetId,
            mb_substr($details, 0, 500),
            client_ip(),
        ]
    );
}

/* =====================================================================
 * Errors
 * ===================================================================== */

set_exception_handler(function (Throwable $e): void {
    error_log('[CubeTech Events] ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
    }
    $message = 'Something went wrong on our side. Please try again.';
    if ($e instanceof mysqli_sql_exception && $e->getCode() === 1146) {
        $message = 'The database tables are missing. Open setup.php to install them.';
    }
    if (APP_DEBUG) {
        $message .= ' [' . get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
    }
    if (wants_json()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => $message]);
        return;
    }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Error</title>'
       . '<div style="font-family:system-ui,sans-serif;max-width:640px;margin:3rem auto;padding:1.5rem;border:1px solid #fecaca;'
       . 'background:#fef2f2;border-radius:12px;color:#7f1d1d"><h1 style="margin-top:0">Something went wrong</h1><p>'
       . h($message) . '</p><p><a href="' . h(url('')) . '">Back to events</a></p></div>';
});
