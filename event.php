<?php
require_once __DIR__ . '/config.php';

$e = get_event((int) ($_GET['id'] ?? 0));
if (!$e || $e['status'] !== 'published') {
    render_error_page(404, 'Event not found', 'This event does not exist or is not open to the public.');
}

$ended = event_is_ended($e);
$left = event_spots_left($e);
$full = $left <= 0;
$capacity = (int) $e['capacity'];
$taken = (int) $e['taken'];
$waitlisted = (int) $e['waitlisted_count'];
$registerUrl = url('register.php?event=' . (int) $e['id']);

render_header($e['title'], ['narrow' => true, 'active' => 'events']);
?>
<a class="back-link" href="<?= h(url('')) ?>"><?= icon('arrow-left') ?> All events</a>

<article class="card event-detail">
  <div class="badges"><?= event_public_badges($e) ?></div>
  <h1><?= h($e['title']) ?></h1>

  <dl class="detail-list">
    <div><dt><?= icon('calendar') ?> Date</dt><dd><?= h(fmt_date($e['starts_at'])) ?></dd></div>
    <div><dt><?= icon('clock') ?> Time</dt><dd><?= h(event_time_range($e)) ?> <span class="muted">(Cebu time)</span></dd></div>
    <div><dt><?= icon('pin') ?> Venue</dt><dd><?= h($e['venue']) ?></dd></div>
    <div>
      <dt><?= icon('users') ?> Spots</dt>
      <dd>
        <?php if ($ended): ?>
          Event ended
        <?php elseif ($full): ?>
          <strong class="text-red">Full</strong> · <?= $waitlisted ?> on the waitlist
        <?php else: ?>
          <strong><?= $left ?> of <?= $capacity ?> spots left</strong>
        <?php endif; ?>
      </dd>
    </div>
  </dl>
  <?php if (!$ended): ?>
    <?= progress_bar($taken, $capacity, $taken . ' of ' . $capacity . ' spots taken') ?>
  <?php endif; ?>

  <div class="description"><?= nl2br(h($e['description'])) ?></div>

  <div class="cta">
    <?php if ($ended): ?>
      <button class="btn btn-primary btn-lg btn-block" type="button" disabled>Event ended</button>
    <?php elseif ($full): ?>
      <button class="btn btn-primary btn-lg btn-block" type="button" disabled aria-describedby="full-note">Full</button>
      <p id="full-note" class="muted small center">All <?= $capacity ?> spots are taken. You can still join the waitlist.</p>
      <a class="btn btn-secondary btn-lg btn-block" href="<?= h($registerUrl) ?>"><?= icon('hourglass') ?> Join the waitlist</a>
    <?php else: ?>
      <a class="btn btn-primary btn-lg btn-block" href="<?= h($registerUrl) ?>"><?= icon('ticket') ?> Register</a>
    <?php endif; ?>
  </div>
  <p class="muted small center">Already registered? <a href="<?= h(url('lookup.php')) ?>">Find your ticket</a></p>
</article>
<?php render_footer(); ?>
