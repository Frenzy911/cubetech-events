<?php
/** CSV export of one event's attendee list (opens correctly in Excel). */
require_once __DIR__ . '/../config.php';
$staff = require_organizer();

$e = get_event((int) ($_GET['event_id'] ?? 0));
if (!$e) {
    render_error_page(404, 'Event not found', 'Choose an event from the events list.', 'staff');
}

$rows = db_all(
    'SELECT r.*, cb.name AS checked_in_by_name
       FROM registrations r
  LEFT JOIN staff_users cb ON cb.id = r.checked_in_by
      WHERE r.event_id = ?
   ORDER BY r.id',
    [(int) $e['id']]
);

/** Stops spreadsheet formula injection (cells starting with = + - @). */
function csv_safe(string $value): string
{
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

$slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($e['title'])), '-');
$filename = 'attendees-' . $slug . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows accents (ñ, é) correctly

fputcsv($out, ['Event', $e['title']]);
fputcsv($out, ['Date', fmt_date($e['starts_at']) . ' ' . event_time_range($e)]);
fputcsv($out, ['Registered / capacity', $e['taken'] . ' / ' . $e['capacity']]);
fputcsv($out, ['Checked in / registered', $e['checked_in_count'] . ' / ' . $e['taken']]);
fputcsv($out, []);
fputcsv($out, ['Name', 'Email', 'Mobile', 'Ticket code', 'Status', 'Registered at', 'Checked in at', 'Checked in by', 'Cancelled at']);

foreach ($rows as $r) {
    fputcsv($out, [
        csv_safe($r['full_name']),
        csv_safe($r['email']),
        '="' . $r['mobile'] . '"', // keeps the leading 0 in Excel
        $r['ticket_code'],
        status_label($r['status']),
        fmt_datetime($r['registered_at']),
        $r['checked_in_at'] ? fmt_datetime($r['checked_in_at']) : '',
        csv_safe((string) $r['checked_in_by_name']),
        $r['cancelled_at'] ? fmt_datetime($r['cancelled_at']) : '',
    ]);
}
fclose($out);
