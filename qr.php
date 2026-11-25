<?php
/**
 * QR image for a ticket (used in emails). Needs the ticket code + its signature,
 * and only works for confirmed / checked-in tickets.
 */
require_once __DIR__ . '/config.php';

$code = normalize_ticket_code((string) ($_GET['code'] ?? ''));
$sig = (string) ($_GET['sig'] ?? '');
$status = ticket_sig_valid($code, $sig) ? db_value('SELECT status FROM registrations WHERE ticket_code = ?', [$code]) : null;

if (!in_array($status, ['confirmed', 'checked_in'], true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: private, max-age=300');
echo QrCode::svg($code);
