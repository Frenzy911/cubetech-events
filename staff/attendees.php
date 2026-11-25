<?php
require_once __DIR__ . '/../config.php';
$staff = require_organizer();

$e = get_event((int) ($_GET['event_id'] ?? 0));
if (!$e) {
    render_error_page(404, 'Event not found', 'Choose an event from the events list.', 'staff');
}
$eventId = (int) $e['id'];

$q = get_str('q', 100);
$statusFilter = get_str('status', 20);
$validStatuses = ['confirmed', 'checked_in', 'waitlisted', 'cancelled'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

$sql = 'SELECT r.*, cb.name AS checked_in_by_name, ub.name AS undone_by_name, xb.name AS cancelled_by_name
          FROM registrations r
     LEFT JOIN staff_users cb ON cb.id = r.checked_in_by
     LEFT JOIN staff_users ub ON ub.id = r.checkin_undone_by
     LEFT JOIN staff_users xb ON xb.id = r.cancelled_by
         WHERE r.event_id = ?';
$params = [$eventId];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $sql .= ' AND (r.full_name LIKE ? OR r.ticket_code LIKE ? OR r.email LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($statusFilter !== '') {
    $sql .= ' AND r.status = ?';
    $params[] = $statusFilter;
}
$sql .= ' ORDER BY r.id ASC';
$rows = db_all($sql, $params);

// Waitlist positions (first come, first served)
$waitIds = array_column(db_all("SELECT id FROM registrations WHERE event_id = ? AND status = 'waitlisted' ORDER BY id", [$eventId]), 'id');
$waitPos = array_flip(array_map('intval', $waitIds));
$nextWait = $waitIds ? db_one('SELECT id, full_name FROM registrations WHERE id = ?', [(int) $waitIds[0]]) : null;

$capacity = (int) $e['capacity'];
$registered = (int) $e['taken'];
$checkedIn = (int) $e['checked_in_count'];
$spotsLeft = event_spots_left($e);
$canPromote = $nextWait && $spotsLeft > 0;

$filterUrl = function (string $status) use ($eventId, $q): string {
    $params = ['event_id' => $eventId];
    if ($q !== '') {
        $params['q'] = $q;
    }
    if ($status !== '') {
        $params['status'] = $status;
    }
    return url('staff/attendees.php?' . http_build_query($params));
};
$counts = [
    ''           => $registered + (int) $e['waitlisted_count'] + (int) $e['cancelled_count'],
    'confirmed'  => (int) $e['confirmed_count'],
    'checked_in' => $checkedIn,
    'waitlisted' => (int) $e['waitlisted_count'],
    'cancelled'  => (int) $e['cancelled_count'],
];

render_header('Attendees · ' . $e['title'], ['area' => 'staff', 'active' => 'events']);
?>
<a class="back-link" href="<?= h(url('staff/events.php')) ?>"><?= icon('arrow-left') ?> Events</a>

<div class="page-head">
  <div>
    <h1><?= h($e['title']) ?></h1>
    <p class="muted"><?= h(fmt_date($e['starts_at'])) ?> · <?= h(event_time_range($e)) ?> · <?= h($e['venue']) ?></p>
    <div class="badges"><?= event_status_badge($e['status']) ?><?= event_is_ended($e) ? badge('Ended', 'gray') : '' ?></div>
  </div>
  <div class="page-actions">
    <a class="btn btn-secondary" href="<?= h(url('staff/checkin.php?event_id=' . $eventId)) ?>"><?= icon('scan') ?> Check-in screen</a>
    <a class="btn btn-secondary" href="<?= h(url('staff/export.php?event_id=' . $eventId)) ?>"><?= icon('download') ?> Export CSV</a>
    <a class="btn btn-ghost" href="<?= h(url('staff/event_form.php?id=' . $eventId)) ?>"><?= icon('edit') ?> Edit event</a>
  </div>
</div>

<div class="stat-grid">
  <div class="stat">
    <span class="stat-label">Registered / capacity</span>
    <span class="stat-value"><?= $registered ?> <small>/ <?= $capacity ?></small></span>
    <?= progress_bar($registered, $capacity, "$registered of $capacity spots taken") ?>
    <span class="stat-note"><?= $spotsLeft > 0 ? $spotsLeft . ' spots left' : 'Full' ?></span>
  </div>
  <div class="stat">
    <span class="stat-label">Checked in / registered</span>
    <span class="stat-value"><?= $checkedIn ?> <small>/ <?= $registered ?></small></span>
    <?= progress_bar($checkedIn, max(1, $registered), "$checkedIn of $registered checked in") ?>
    <span class="stat-note"><?= event_is_started($e) ? ((int) $e['confirmed_count']) . ' not checked in yet' : 'Event not started' ?></span>
  </div>
  <div class="stat">
    <span class="stat-label">Waitlisted</span>
    <span class="stat-value"><?= (int) $e['waitlisted_count'] ?></span>
    <span class="stat-note"><?= $nextWait ? 'Next: ' . h($nextWait['full_name']) : 'Nobody waiting' ?></span>
  </div>
  <div class="stat">
    <span class="stat-label">Cancelled</span>
    <span class="stat-value"><?= (int) $e['cancelled_count'] ?></span>
  </div>
</div>

<?php if ($nextWait): ?>
  <div class="card promote-box">
    <div>
      <strong><?= icon('up') ?> Waitlist</strong>
      <p class="muted small">
        <?= $canPromote
            ? 'A spot is free. Promote the first person on the waitlist (' . h($nextWait['full_name']) . ') to a confirmed ticket. They get an email with their QR ticket.'
            : 'The event is full. Cancel a registration or raise the capacity to promote someone from the waitlist.' ?>
      </p>
    </div>
    <form method="post" action="<?= h(url('staff/registration_action.php')) ?>" data-confirm="Promote <?= h($nextWait['full_name']) ?> from the waitlist to a confirmed ticket?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="promote">
      <input type="hidden" name="event_id" value="<?= $eventId ?>">
      <button type="submit" class="btn btn-primary"<?= $canPromote ? '' : ' disabled' ?>><?= icon('up') ?> Promote next (<?= h($nextWait['full_name']) ?>)</button>
    </form>
  </div>
<?php endif; ?>

<section class="card card-flush">
  <div class="card-head card-head-wrap">
    <form method="get" class="search-form" role="search">
      <input type="hidden" name="event_id" value="<?= $eventId ?>">
      <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?= h($statusFilter) ?>"><?php endif; ?>
      <label for="q" class="sr-only">Search by name, ticket code or email</label>
      <input type="search" id="q" name="q" value="<?= h($q) ?>" placeholder="Search name, ticket code or email">
      <button class="btn btn-secondary" type="submit"><?= icon('search') ?> Search</button>
      <?php if ($q !== ''): ?><a class="btn btn-ghost" href="<?= h($filterUrl($statusFilter)) ?>">Clear</a><?php endif; ?>
    </form>
    <nav class="tabs tabs-sm" aria-label="Filter by status">
      <?php foreach (['' => 'All', 'confirmed' => 'Confirmed', 'checked_in' => 'Checked in', 'waitlisted' => 'Waitlisted', 'cancelled' => 'Cancelled'] as $key => $label): ?>
        <a href="<?= h($filterUrl($key)) ?>"<?= $statusFilter === $key ? ' class="is-active" aria-current="page"' : '' ?>><?= h($label) ?> <span class="count"><?= $counts[$key] ?></span></a>
      <?php endforeach; ?>
    </nav>
  </div>

  <?php if (!$rows): ?>
    <p class="muted pad"><?= $q !== '' ? 'No registrations match "' . h($q) . '".' : 'No registrations yet.' ?></p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table table-attendees table-stack">
      <thead>
        <tr>
          <th scope="col">Name</th>
          <th scope="col">Email</th>
          <th scope="col">Mobile</th>
          <th scope="col">Ticket code</th>
          <th scope="col">Registered at</th>
          <th scope="col">Status</th>
          <th scope="col"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): $rid = (int) $r['id']; ?>
        <tr class="row-<?= h($r['status']) ?>">
          <td class="strong span-all"><?= h($r['full_name']) ?></td>
          <td class="span-all" data-label="Email"><?= h($r['email']) ?></td>
          <td class="nowrap" data-label="Mobile"><?= h($r['mobile']) ?></td>
          <td class="mono nowrap" data-label="Ticket code"><?= h($r['ticket_code']) ?></td>
          <td class="nowrap small" data-label="Registered at"><?= h(fmt_datetime($r['registered_at'])) ?></td>
          <td data-label="Status">
            <?= status_badge($r['status'], isset($waitPos[$rid]) ? $waitPos[$rid] + 1 : null) ?>
            <?php if ($r['status'] === 'checked_in'): ?>
              <div class="small muted"><?= h(fmt_datetime($r['checked_in_at'])) ?><?= $r['checked_in_by_name'] ? ' · ' . h($r['checked_in_by_name']) : '' ?></div>
            <?php elseif ($r['status'] === 'cancelled'): ?>
              <div class="small muted"><?= h(fmt_datetime($r['cancelled_at'])) ?> · <?= $r['cancelled_by_name'] ? h($r['cancelled_by_name']) : 'by attendee' ?></div>
            <?php endif; ?>
            <?php if ($r['checkin_undone_at']): ?>
              <div class="small muted"><?= icon('undo') ?> Check-in undone <?= h(fmt_datetime($r['checkin_undone_at'])) ?><?= $r['undone_by_name'] ? ' by ' . h($r['undone_by_name']) : '' ?></div>
            <?php endif; ?>
          </td>
          <td class="actions-cell">
            <?php if ($r['status'] === 'checked_in'): ?>
              <form method="post" action="<?= h(url('staff/registration_action.php')) ?>" data-confirm="Undo the check-in for <?= h($r['full_name']) ?>? Their ticket can then be checked in again.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="undo_checkin">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <input type="hidden" name="registration_id" value="<?= $rid ?>">
                <button class="btn btn-sm btn-secondary" type="submit"><?= icon('undo') ?> Undo check-in</button>
              </form>
            <?php elseif (in_array($r['status'], ['confirmed', 'waitlisted'], true)): ?>
              <form method="post" action="<?= h(url('staff/registration_action.php')) ?>" data-confirm="Cancel the registration for <?= h($r['full_name']) ?>?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <input type="hidden" name="registration_id" value="<?= $rid ?>">
                <button class="btn btn-sm btn-danger-outline" type="submit"><?= icon('x') ?> Cancel</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php render_footer(['area' => 'staff']); ?>
