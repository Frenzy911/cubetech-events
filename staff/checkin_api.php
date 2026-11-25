<?php
/**
 * Check-in API used by the check-in screen (JSON).
 *   POST action=checkin  event_id, code   -> check a ticket in
 *   POST action=undo     event_id, code   -> undo a check-in
 *   POST action=stats    event_id         -> live counter
 */
require_once __DIR__ . '/../config.php';

$_SERVER['HTTP_ACCEPT'] = 'application/json'; // all errors from here are JSON
$staff = require_staff();

if (!is_post()) {
    json_response(['ok' => false, 'error' => 'Use POST.'], 405);
}
csrf_verify();

$event = get_event((int) ($_POST['event_id'] ?? 0));
if (!$event) {
    json_response(['ok' => false, 'error' => 'Event not found.'], 404);
}
$eventId = (int) $event['id'];
$action = post_str('action', 20);

switch ($action) {
    case 'stats':
        json_response(['ok' => true, 'stats' => checkin_stats($eventId)]);

    case 'checkin':
        // Rate limit: 60 scans per minute per staff account.
        $ident = 'staff:' . $staff['id'];
        if (rate_limited('checkin', $ident, 60, 60)) {
            json_response(checkin_result('rate_limited', post_str('code', 40)) + ['stats' => checkin_stats($eventId)], 429);
        }
        rate_hit('checkin', $ident);
        $result = check_in_ticket($event, post_str('code', 60), $staff);
        json_response($result + ['stats' => checkin_stats($eventId), 'at' => fmt_time(now_str())]);

    case 'undo':
        $code = normalize_ticket_code(post_str('code', 60));
        $reg = $code !== '' ? get_registration_by_code($code) : null;
        if (!$reg || (int) $reg['event_id'] !== $eventId) {
            json_response(checkin_result('invalid', $code) + ['stats' => checkin_stats($eventId)]);
        }
        $undo = undo_checkin($reg, $staff);
        $payload = $undo['ok']
            ? ['tone' => 'blue', 'title' => 'Check-in undone', 'detail' => $reg['full_name'] . ' can be checked in again. The undo was recorded in the audit log.', 'result' => 'undone']
            : ['tone' => 'yellow', 'title' => 'Nothing to undo', 'detail' => $undo['error'], 'result' => 'undo_failed'];
        json_response($payload + [
            'ok' => true, 'name' => $reg['full_name'], 'code' => $code, 'can_undo' => false,
            'stats' => checkin_stats($eventId), 'at' => fmt_time(now_str()),
        ]);

    default:
        json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
}
