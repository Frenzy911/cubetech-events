<?php
/** Organizer actions from the attendee list: cancel, undo check-in, promote from waitlist. */
require_once __DIR__ . '/../config.php';
$staff = require_organizer();

if (!is_post()) {
    redirect('staff/events.php');
}
csrf_verify();

$eventId = (int) ($_POST['event_id'] ?? 0);
$action = post_str('action', 30);
$back = 'staff/attendees.php?event_id=' . $eventId;

if ($action === 'promote') {
    $result = promote_next($eventId, $staff);
    $result['ok']
        ? flash('success', $result['name'] . ' was moved from the waitlist to a confirmed ticket (' . $result['code'] . '). A confirmation email was sent.')
        : flash('error', $result['error']);
    redirect($back);
}

$reg = get_registration((int) ($_POST['registration_id'] ?? 0));
if (!$reg || (int) $reg['event_id'] !== $eventId) {
    flash('error', 'Registration not found.');
    redirect($back);
}

switch ($action) {
    case 'cancel':
        $result = cancel_registration($reg, $staff);
        $result['ok']
            ? flash('success', 'Cancelled the registration for ' . $reg['full_name'] . '. One spot is free again.')
            : flash('error', $result['error']);
        break;
    case 'undo_checkin':
        $result = undo_checkin($reg, $staff);
        $result['ok']
            ? flash('success', 'Check-in undone for ' . $reg['full_name'] . '. Their ticket can be checked in again.')
            : flash('error', $result['error']);
        break;
    default:
        flash('error', 'Unknown action.');
}
redirect($back);
