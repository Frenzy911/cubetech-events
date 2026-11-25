<?php
require_once __DIR__ . '/../config.php';
$staff = require_organizer();

$view = in_array(get_str('view'), ['upcoming', 'past', 'all'], true) ? get_str('view') : 'upcoming';
$where = ['upcoming' => 'WHERE e.ends_at > NOW()', 'past' => 'WHERE e.ends_at <= NOW()', 'all' => ''][$view];
$order = $view === 'past' ? 'e.starts_at DESC' : 'e.starts_at ASC';
$events = db_all(EVENT_SQL . " $where GROUP BY e.id ORDER BY $order");

render_header('Events', ['area' => 'staff', 'active' => 'events']);
?>
<div class="page-head">
  <div>
    <h1>Events</h1>
    <p class="muted">Only <strong>Published</strong> events are visible to attendees. <strong>Closed</strong> events stop taking registrations.</p>
  </div>
  <a class="btn btn-primary" href="<?= h(url('staff/event_form.php')) ?>"><?= icon('plus') ?> New event</a>
</div>

<nav class="tabs" aria-label="Filter events">
  <?php foreach (['upcoming' => 'Upcoming', 'past' => 'Past', 'all' => 'All'] as $key => $label): ?>
    <a href="<?= h(url('staff/events.php?view=' . $key)) ?>"<?= $view === $key ? ' class="is-active" aria-current="page"' : '' ?>><?= h($label) ?></a>
  <?php endforeach; ?>
</nav>

<section class="card card-flush">
  <?php if (!$events): ?>
    <p class="muted pad">No events here.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table table-stack">
      <thead>
        <tr>
          <th scope="col">Event</th>
          <th scope="col">When</th>
          <th scope="col">Status</th>
          <th scope="col" class="num">Registered</th>
          <th scope="col" class="num">Waitlist</th>
          <th scope="col"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($events as $e): ?>
          <tr>
            <td class="col-main">
              <a class="strong" href="<?= h(url('staff/attendees.php?event_id=' . (int) $e['id'])) ?>"><?= h($e['title']) ?></a>
              <div class="small muted"><?= h($e['venue']) ?></div>
            </td>
            <td class="nowrap" data-label="When"><?= h(fmt_date($e['starts_at'])) ?><div class="small muted"><?= h(event_time_range($e)) ?></div></td>
            <td data-label="Status"><?= event_status_badge($e['status']) ?><?= event_is_ended($e) ? ' ' . badge('Ended', 'gray') : '' ?></td>
            <td class="num" data-label="Registered"><?= (int) $e['taken'] ?> / <?= (int) $e['capacity'] ?></td>
            <td class="num" data-label="Waitlist"><?= (int) $e['waitlisted_count'] ?></td>
            <td class="actions-cell">
              <a class="btn btn-sm btn-secondary" href="<?= h(url('staff/event_form.php?id=' . (int) $e['id'])) ?>"><?= icon('edit') ?> Edit</a>
              <a class="btn btn-sm btn-secondary" href="<?= h(url('staff/attendees.php?event_id=' . (int) $e['id'])) ?>"><?= icon('list') ?> Attendees</a>
              <a class="btn btn-sm btn-secondary" href="<?= h(url('staff/checkin.php?event_id=' . (int) $e['id'])) ?>"><?= icon('scan') ?> Check-in</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php render_footer(['area' => 'staff']); ?>
