<?php
require_once __DIR__ . '/config.php';

// Attendees only ever see Published events.
$upcoming = db_all(EVENT_SQL . " WHERE e.status = 'published' AND e.ends_at > NOW() GROUP BY e.id ORDER BY e.starts_at ASC");
$past = db_all(EVENT_SQL . " WHERE e.status = 'published' AND e.ends_at <= NOW() GROUP BY e.id ORDER BY e.starts_at DESC LIMIT 6");

render_header('Upcoming events', ['active' => 'events']);
?>
<section class="hero">
  <div>
    <h1>Upcoming events</h1>
    <p class="lead">Register for free, get a QR ticket on your phone, and show it at the door.</p>
  </div>
  <a class="btn btn-secondary" href="<?= h(url('lookup.php')) ?>"><?= icon('search') ?> Find my ticket</a>
</section>

<?php if (!$upcoming): ?>
  <section class="card empty-state">
    <div class="empty-icon"><?= icon('calendar') ?></div>
    <h2>No upcoming events</h2>
    <p class="muted">Check back soon. New events are posted here.</p>
  </section>
<?php else: ?>
  <div class="event-list">
    <?php foreach ($upcoming as $e): ?>
      <?= event_card($e) ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($past): ?>
  <h2 class="section-title">Past events</h2>
  <div class="event-list event-list-past">
    <?php foreach ($past as $e): ?>
      <?= event_card($e) ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php render_footer(); ?>
