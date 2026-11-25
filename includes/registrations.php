<?php
/**
 * Registration and check-in rules. Everything here runs on the server, so the
 * rules hold even if someone bypasses the screens.
 *
 *  - Capacity counts registrations with status confirmed or checked_in.
 *  - One active (not cancelled) registration per email per event.
 *  - Last-spot race: the event row is locked with SELECT ... FOR UPDATE while we
 *    count and insert, so two people can never both take the last spot.
 *  - Check-in race: a conditional UPDATE ... WHERE status = 'confirmed' means only
 *    one device can switch a ticket to checked_in; the other sees "Already checked in".
 */

function result_fail(string $reason, string $message): array
{
    return ['ok' => false, 'reason' => $reason, 'error' => $message];
}

function status_label(string $status): string
{
    return [
        'confirmed'  => 'Confirmed',
        'checked_in' => 'Checked in',
        'waitlisted' => 'Waitlisted',
        'cancelled'  => 'Cancelled',
    ][$status] ?? ucfirst($status);
}

/** Registration + its event (title, venue, times, status). */
function get_registration(int $id): ?array
{
    return db_one(
        'SELECT r.*, e.title AS event_title, e.venue, e.starts_at, e.ends_at, e.status AS event_status,
                cb.name AS checked_in_by_name, ub.name AS undone_by_name, xb.name AS cancelled_by_name
           FROM registrations r
           JOIN events e ON e.id = r.event_id
      LEFT JOIN staff_users cb ON cb.id = r.checked_in_by
      LEFT JOIN staff_users ub ON ub.id = r.checkin_undone_by
      LEFT JOIN staff_users xb ON xb.id = r.cancelled_by
          WHERE r.id = ?',
        [$id]
    );
}

function get_registration_by_code(string $code): ?array
{
    $id = db_value('SELECT id FROM registrations WHERE ticket_code = ?', [$code]);
    return $id ? get_registration((int) $id) : null;
}

/** 1-based position on the waitlist (first come, first served). */
function waitlist_position(array $reg): int
{
    return (int) db_value(
        "SELECT COUNT(*) FROM registrations WHERE event_id = ? AND status = 'waitlisted' AND id <= ?",
        [(int) $reg['event_id'], (int) $reg['id']]
    );
}

/**
 * Registers an attendee. $intent is "register" or "waitlist".
 * Returns ['ok' => true, 'id', 'code', 'status'] or a result_fail() array.
 */
function register_attendee(int $eventId, array $v, string $intent): array
{
    $db = db();
    $db->begin_transaction();
    try {
        // 1) Lock this event's row. Other registrations for the same event wait here until we commit.
        $event = db_one('SELECT id, title, status, capacity, ends_at FROM events WHERE id = ? FOR UPDATE', [$eventId]);
        if (!$event || $event['status'] !== 'published' || strtotime($event['ends_at']) <= time()) {
            $db->rollback();
            return result_fail('closed', 'Registration is closed for this event.');
        }

        // 2) One active registration per email per event.
        $existing = (int) db_value(
            "SELECT COUNT(*) FROM registrations WHERE event_id = ? AND email = ? AND status <> 'cancelled'",
            [$eventId, $v['email']]
        );
        if ($existing > 0) {
            $db->rollback();
            return result_fail('duplicate', 'This email is already registered for this event.');
        }

        // 3) Check capacity again while holding the lock (never trust the disabled button).
        $taken = (int) db_value(
            "SELECT COUNT(*) FROM registrations WHERE event_id = ? AND status IN ('confirmed','checked_in')",
            [$eventId]
        );
        if ($taken < (int) $event['capacity']) {
            $status = 'confirmed';
        } elseif ($intent === 'waitlist') {
            $status = 'waitlisted';
        } else {
            $db->rollback();
            return result_fail('full', 'Sorry, the last spot was just taken and this event is now full. You can join the waitlist instead.');
        }

        // 4) Save with a unique, random ticket code.
        $id = 0;
        $code = '';
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $code = generate_ticket_code($event['title']);
            try {
                $id = db_insert(
                    'INSERT INTO registrations (event_id, full_name, email, mobile, ticket_code, status, registered_at, confirmed_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)',
                    [$eventId, $v['full_name'], $v['email'], $v['mobile'], $code, $status, $status === 'confirmed' ? now_str() : null]
                );
                break;
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() === 1062 && str_contains($e->getMessage(), 'uq_reg_ticket_code') && $attempt < 5) {
                    continue; // astronomically rare code collision - try a new code
                }
                throw $e;
            }
        }

        $db->commit();
        return ['ok' => true, 'id' => $id, 'code' => $code, 'status' => $status];
    } catch (mysqli_sql_exception $e) {
        $db->rollback();
        if ($e->getCode() === 1062) { // unique index uq_reg_active_email caught a duplicate
            return result_fail('duplicate', 'This email is already registered for this event.');
        }
        throw $e;
    }
}

/** Cancels a confirmed or waitlisted registration. $staff = null when the attendee cancels. */
function cancel_registration(array $reg, ?array $staff): array
{
    if ($reg['status'] === 'checked_in') {
        return result_fail('checked_in', "This ticket has already been checked in, so it can't be cancelled.");
    }
    if ($reg['status'] === 'cancelled') {
        return result_fail('cancelled', 'This registration is already cancelled.');
    }

    $changed = db_exec(
        "UPDATE registrations SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?
          WHERE id = ? AND status IN ('confirmed','waitlisted')",
        [$staff ? (int) $staff['id'] : null, (int) $reg['id']]
    );
    if ($changed !== 1) {
        $now = db_value('SELECT status FROM registrations WHERE id = ?', [(int) $reg['id']]);
        return $now === 'checked_in'
            ? result_fail('checked_in', "This ticket was just checked in, so it can't be cancelled.")
            : result_fail('changed', 'This registration changed a moment ago. Reload the page and try again.');
    }

    audit('registration_cancelled', 'registration', (int) $reg['id'], sprintf(
        '%s (%s) was cancelled %s for "%s" (was %s)',
        $reg['full_name'],
        $reg['ticket_code'],
        $staff ? 'by ' . $staff['name'] : 'by the attendee',
        $reg['event_title'],
        strtolower(status_label($reg['status']))
    ), $staff);

    return ['ok' => true];
}

/** Moves the first person on the waitlist to confirmed, if a spot is free. */
function promote_next(int $eventId, array $staff): array
{
    $db = db();
    $db->begin_transaction();
    try {
        $event = db_one('SELECT id, title, capacity FROM events WHERE id = ? FOR UPDATE', [$eventId]);
        if (!$event) {
            $db->rollback();
            return result_fail('not_found', 'Event not found.');
        }
        $taken = (int) db_value(
            "SELECT COUNT(*) FROM registrations WHERE event_id = ? AND status IN ('confirmed','checked_in')",
            [$eventId]
        );
        if ($taken >= (int) $event['capacity']) {
            $db->rollback();
            return result_fail('full', 'No spot is free. Cancel a registration or raise the capacity first.');
        }
        $next = db_one(
            "SELECT id, full_name, ticket_code FROM registrations
              WHERE event_id = ? AND status = 'waitlisted' ORDER BY id LIMIT 1 FOR UPDATE",
            [$eventId]
        );
        if (!$next) {
            $db->rollback();
            return result_fail('empty', 'Nobody is on the waitlist.');
        }
        db_exec(
            "UPDATE registrations SET status = 'confirmed', confirmed_at = NOW() WHERE id = ? AND status = 'waitlisted'",
            [(int) $next['id']]
        );
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }

    audit('waitlist_promoted', 'registration', (int) $next['id'], sprintf(
        'Promoted %s (%s) from the waitlist for "%s"',
        $next['full_name'],
        $next['ticket_code'],
        $event['title']
    ), $staff);
    send_ticket_email((int) $next['id'], 'promoted');

    return ['ok' => true, 'name' => $next['full_name'], 'code' => $next['ticket_code']];
}

/**
 * Door check-in. Two checks in order:
 *   1. Does the code belong to the selected event?  (no -> Invalid ticket)
 *   2. What is its status?  Only a confirmed ticket, on the event day, gets checked in.
 */
function check_in_ticket(array $event, string $rawCode, array $staff): array
{
    $code = normalize_ticket_code($rawCode);
    $reg = $code !== '' ? get_registration_by_code($code) : null;

    if (!$reg || (int) $reg['event_id'] !== (int) $event['id']) {
        return checkin_result('invalid', $code);
    }
    if ($reg['status'] !== 'confirmed') {
        return checkin_result($reg['status'] === 'checked_in' ? 'already' : $reg['status'], $code, $reg, $event);
    }
    if (!event_is_today($event)) {
        return checkin_result('not_event_day', $code, $reg, $event);
    }

    // Atomic: only one request can flip confirmed -> checked_in.
    $won = db_exec(
        "UPDATE registrations SET status = 'checked_in', checked_in_at = NOW(), checked_in_by = ?
          WHERE id = ? AND status = 'confirmed'",
        [(int) $staff['id'], (int) $reg['id']]
    ) === 1;

    $fresh = get_registration((int) $reg['id']);
    if ($won) {
        audit('check_in', 'registration', (int) $reg['id'], sprintf(
            'Checked in %s (%s) for "%s"',
            $reg['full_name'],
            $reg['ticket_code'],
            $event['title']
        ), $staff);
        return checkin_result('checked_in', $code, $fresh, $event);
    }
    // Another device changed this ticket a split second earlier.
    return checkin_result($fresh['status'] === 'checked_in' ? 'already' : $fresh['status'], $code, $fresh, $event);
}

/** Text + colour for the check-in screen. Colour is never the only signal: every result has a title. */
function checkin_result(string $type, string $code, ?array $reg = null, ?array $event = null): array
{
    $name = $reg['full_name'] ?? '';
    $when = function (?string $dt): string {
        if (!$dt) {
            return '—';
        }
        return date('Y-m-d', strtotime($dt)) === date('Y-m-d') ? fmt_time($dt) : fmt_datetime($dt);
    };

    switch ($type) {
        case 'checked_in':
            $r = ['tone' => 'green', 'title' => 'Checked in', 'detail' => 'Welcome! Checked in at ' . $when($reg['checked_in_at']) . '.'];
            break;
        case 'already':
            $by = !empty($reg['checked_in_by_name']) ? ' by ' . $reg['checked_in_by_name'] : '';
            $r = ['tone' => 'yellow', 'title' => 'Already checked in', 'detail' => 'First checked in at ' . $when($reg['checked_in_at']) . $by . '.'];
            break;
        case 'waitlisted':
            $r = ['tone' => 'red', 'title' => 'On waitlist — not confirmed', 'detail' => 'This person is on the waitlist and does not have a confirmed ticket.'];
            break;
        case 'cancelled':
            $r = ['tone' => 'red', 'title' => 'Cancelled', 'detail' => 'This ticket was cancelled on ' . fmt_datetime($reg['cancelled_at']) . '.'];
            break;
        case 'not_event_day':
            $r = ['tone' => 'red', 'title' => 'Not the event day', 'detail' => 'Check-in for this event is only open on ' . fmt_date($event['starts_at']) . '.'];
            break;
        case 'rate_limited':
            $r = ['tone' => 'red', 'title' => 'Too many scans', 'detail' => 'Please wait a few seconds, then scan again.'];
            break;
        case 'invalid':
        default:
            $type = 'invalid';
            $name = '';
            $r = ['tone' => 'red', 'title' => 'Invalid ticket', 'detail' => 'This code does not exist or belongs to a different event.'];
            break;
    }

    return $r + [
        'ok'       => true,
        'result'   => $type,
        'name'     => $name,
        'code'     => $code,
        'can_undo' => in_array($type, ['checked_in', 'already'], true),
    ];
}

/** Reverts a mistaken check-in and records who did it. */
function undo_checkin(array $reg, array $staff): array
{
    if ($reg['status'] !== 'checked_in') {
        return result_fail('not_checked_in', "This ticket isn't checked in, so there is nothing to undo.");
    }
    $changed = db_exec(
        "UPDATE registrations
            SET status = 'confirmed', checked_in_at = NULL, checked_in_by = NULL,
                checkin_undone_at = NOW(), checkin_undone_by = ?
          WHERE id = ? AND status = 'checked_in'",
        [(int) $staff['id'], (int) $reg['id']]
    );
    if ($changed !== 1) {
        return result_fail('changed', 'This ticket changed a moment ago. Reload and try again.');
    }

    audit('check_in_undone', 'registration', (int) $reg['id'], sprintf(
        'Undid check-in of %s (%s) for "%s" - had been checked in at %s%s',
        $reg['full_name'],
        $reg['ticket_code'],
        $reg['event_title'],
        fmt_datetime($reg['checked_in_at']),
        !empty($reg['checked_in_by_name']) ? ' by ' . $reg['checked_in_by_name'] : ''
    ), $staff);

    return ['ok' => true];
}

/** Live numbers for the check-in counter. */
function checkin_stats(int $eventId): array
{
    $row = db_one(
        "SELECT COALESCE(SUM(status = 'checked_in'), 0) AS checked_in,
                COALESCE(SUM(status IN ('confirmed','checked_in')), 0) AS registered
           FROM registrations WHERE event_id = ?",
        [$eventId]
    );
    return ['checked_in' => (int) $row['checked_in'], 'registered' => (int) $row['registered']];
}
