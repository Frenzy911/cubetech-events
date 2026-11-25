<?php
require_once __DIR__ . '/../config.php';

$staff = require_staff();
if ($staff['role'] !== 'organizer') {
    redirect('staff/checkin.php'); // door staff only have the check-in screen
}

$events = db_all(EVENT_SQL . ' WHERE e.ends_at > NOW() GROUP BY e.id ORDER BY e.starts_at ASC');
$recent = db_all('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 8');

$totals = ['events' => count($events), 'confirmed' => 0, 'waitlisted' => 0, 'checked_in' => 0];
foreach ($events as $e) {
    $totals['confirmed'] += (int) $e['taken'];
    $totals['waitlisted'] += (int) $e['waitlisted_count'];
    $totals['checked_in'] += (int) $e['checked_in_count'];
}

render_header('Dashboard', ['area' => 'staff', 'active' => 'dashboard']);
?>
<div class="page-head">
  <div>
    <h1>Dashboard</h1>
    <p class="muted">Upcoming and ongoing events. Counts update live from the database.</p>
  </div>
  <a class="btn btn-primary" href="<?= h(url('staff/event_form.php')) ?>"><?= icon('plus') ?> New event</a>
</div>

<div class="stat-grid">
  <div class="stat"><span class="stat-label">Upcoming events</span><span class="stat-value"><?= $totals['events'] ?></span></div>
  <div class="stat"><span class="stat-label">Confirmed tickets</span><span class="stat-value"><?= $totals['confirmed'] ?></span></div>
  <div class="stat"><span class="stat-label">On waitlists</span><span class="stat-value"><?= $totals['waitlisted'] ?></span></div>
  <div class="stat"><span class="stat-label">Checked in</span><span class="stat-value"><?= $totals['checked_in'] ?></span></div>
</div>

<section class="card card-flush">
  <div class="card-head"><h2>Events</h2></div>
  <?php if (!$events): ?>
    <p class="muted pad">No upcoming events. <a href="<?= h(url('staff/event_form.php')) ?>">Create one</a>.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table table-stack table-stack-3">
      <thead>
        <tr>
          <th scope="col">Event</th>
          <th scope="col" class="num">Capacity</th>
          <th scope="col" class="num">Confirmed<span class="sr-only"> (includes checked in)</span></th>
          <th scope="col" class="num">Waitlisted</th>
          <th scope="col" class="num">Cancelled</th>
          <th scope="col" class="num">Checked in</th>
          <th scope="col" class="num">No-shows</th>
          <th scope="col"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($events as $e): $started = event_is_started($e); ?>
        <tr>
          <td class="col-main">
            <a class="strong" href="<?= h(url('staff/attendees.php?event_id=' . (int) $e['id'])) ?>"><?= h($e['title']) ?></a>
            <div class="small muted"><?= h(fmt_date($e['starts_at'])) ?> · <?= h(event_time_range($e)) ?></div>
            <div class="badges"><?= event_status_badge($e['status']) ?><?= $started ? badge('Started', 'green', 'clock') : '' ?><?= event_is_full($e) ? badge('Full', 'red', 'users') : '' ?></div>
          </td>
          <td class="num" data-label="Capacity"><?= (int) $e['capacity'] ?></td>
          <td class="num" data-label="Confirmed"><?= (int) $e['taken'] ?></td>
          <td class="num" data-label="Waitlisted"><?= (int) $e['waitlisted_count'] ?></td>
          <td class="num" data-label="Cancelled"><?= (int) $e['cancelled_count'] ?></td>
          <td class="num" data-label="Checked in"><?= (int) $e['checked_in_count'] ?></td>
          <td class="num" data-label="No-shows"><?= $started ? (int) $e['confirmed_count'] : '<span class="muted" title="Shown after the event starts">—</span>' ?></td>
          <td class="actions-cell">
            <a class="btn btn-sm btn-secondary" href="<?= h(url('staff/attendees.php?event_id=' . (int) $e['id'])) ?>"><?= icon('list') ?> Attendees</a>
            <a class="btn btn-sm btn-secondary" href="<?= h(url('staff/checkin.php?event_id=' . (int) $e['id'])) ?>"><?= icon('scan') ?> Check-in</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="small muted pad">Confirmed = confirmed + checked in (both hold a spot). No-shows = confirmed but not checked in, shown once the event has started.</p>
  <?php endif; ?>
</section>

<section class="card card-flush">
  <div class="card-head"><h2>Recent activity</h2><a href="<?= h(url('staff/audit.php')) ?>" class="small">Full audit log →</a></div>
  <?php if (!$recent): ?>
    <p class="muted pad">No activity yet.</p>
  <?php else: ?>
    <ul class="activity">
      <?php foreach ($recent as $a): ?>
        <li>
          <span class="activity-time"><?= h(fmt_datetime($a['created_at'])) ?></span>
          <span><strong><?= h($a['actor']) ?></strong> · <?= h(AUDIT_ACTIONS[$a['action']] ?? $a['action']) ?></span>
          <span class="small muted"><?= h($a['details']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php render_footer(['area' => 'staff']); ?>
