<?php
require_once __DIR__ . '/../config.php';
$staff = require_staff(); // organizers and door staff

$eventId = (int) ($_GET['event_id'] ?? 0);

/* ---------------- Step 1: choose an event ---------------- */
if (!$eventId) {
    $events = db_all(EVENT_SQL . " WHERE e.status IN ('published','closed') AND e.ends_at > NOW() - INTERVAL 1 DAY GROUP BY e.id ORDER BY e.starts_at ASC");
    $today = array_filter($events, 'event_is_today');
    $other = array_filter($events, fn ($e) => !event_is_today($e));

    render_header('Check-in', ['area' => 'staff', 'active' => 'checkin', 'narrow' => true]);
    ?>
    <h1>Check-in</h1>
    <p class="muted">Choose the event you are checking people in for.</p>

    <h2 class="section-title">Today</h2>
    <?php if (!$today): ?>
      <div class="card"><p class="muted">No events today.</p></div>
    <?php endif; ?>
    <div class="pick-list">
      <?php foreach ($today as $e): ?>
        <a class="pick-card" href="<?= h(url('staff/checkin.php?event_id=' . (int) $e['id'])) ?>">
          <span class="pick-title"><?= h($e['title']) ?></span>
          <span class="small muted"><?= h(event_time_range($e)) ?> · <?= h($e['venue']) ?></span>
          <span class="pick-count"><?= icon('check') ?> <?= (int) $e['checked_in_count'] ?> / <?= (int) $e['taken'] ?> checked in</span>
          <span class="btn btn-primary"><?= icon('scan') ?> Open check-in</span>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($other): ?>
      <h2 class="section-title">Other events</h2>
      <p class="small muted">Confirmed tickets can only be checked in on the event day.</p>
      <ul class="simple-list card">
        <?php foreach ($other as $e): ?>
          <li>
            <a href="<?= h(url('staff/checkin.php?event_id=' . (int) $e['id'])) ?>"><?= h($e['title']) ?></a>
            <span class="small muted"><?= h(fmt_date($e['starts_at'])) ?> · <?= h(event_time_range($e)) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php
    render_footer(['area' => 'staff']);
    exit;
}

/* ---------------- Step 2: the check-in screen ---------------- */
$e = get_event($eventId);
if (!$e) {
    render_error_page(404, 'Event not found', 'Choose an event from the check-in page.', 'staff');
}
$stats = checkin_stats($eventId);
$isToday = event_is_today($e);
$pct = $stats['registered'] > 0 ? round($stats['checked_in'] / $stats['registered'] * 100) : 0;

$config = [
    'api'       => url('staff/checkin_api.php'),
    'login'     => url('staff/login.php'),
    'eventId'   => $eventId,
    'csrf'      => csrf_token(),
    'resetMs'   => 2200,
    'pollMs'    => 5000,
    'scanLibs'  => [
        'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js',
        'https://unpkg.com/jsqr@1.4.0/dist/jsQR.min.js',
    ],
];

render_header('Check-in · ' . $e['title'], ['area' => 'staff', 'active' => 'checkin', 'body_class' => 'page-checkin']);
?>
<div class="checkin" data-checkin>
  <div class="checkin-top">
    <div>
      <a class="back-link" href="<?= h(url('staff/checkin.php')) ?>"><?= icon('arrow-left') ?> Change event</a>
      <h1 class="checkin-title"><?= h($e['title']) ?></h1>
      <p class="muted small"><?= h(fmt_date($e['starts_at'])) ?> · <?= h(event_time_range($e)) ?> · <?= h($e['venue']) ?></p>
    </div>
    <div class="counter" aria-live="polite">
      <span class="counter-value"><b data-stat="checked_in"><?= $stats['checked_in'] ?></b> / <span data-stat="registered"><?= $stats['registered'] ?></span></span>
      <span class="counter-label">checked in</span>
      <div class="progress"><span data-progress style="width:<?= $pct ?>%"></span></div>
    </div>
  </div>

  <?php if (!$isToday): ?>
    <div class="alert alert-warning" role="note"><?= icon('alert') ?><div><strong>Not the event day.</strong> This event is on <?= h(fmt_date($e['starts_at'])) ?>. You can scan to see a ticket's status, but confirmed tickets will not be checked in today.</div></div>
  <?php endif; ?>

  <div class="checkin-grid">
    <div class="checkin-main">
      <div id="result" class="result result-idle" role="status" aria-live="assertive" aria-atomic="true">
        <div class="result-icon" data-result-icon><?= icon('scan', 'icon') ?></div>
        <div class="result-body">
          <p class="result-title" data-result-title>Ready to scan</p>
          <p class="result-name" data-result-name></p>
          <p class="result-detail" data-result-detail>Scan a QR code or type a ticket code.</p>
          <p class="result-code mono" data-result-code></p>
        </div>
      </div>

      <form id="manual-form" class="card manual-form" autocomplete="off">
        <label for="manual-code" class="label-strong">Ticket code</label>
        <div class="input-row">
          <input id="manual-code" name="code" class="input-code" type="text" inputmode="text" autocapitalize="characters"
                 spellcheck="false" placeholder="CDM-7K3P-9QXW-M2RT" maxlength="60" autofocus required>
          <button class="btn btn-primary btn-lg" type="submit">Check in</button>
        </div>
        <p class="hint">Type the code and press Enter. A USB or Bluetooth QR scanner works too: it types the code and presses Enter for you.</p>
      </form>
    </div>

    <div class="checkin-side">
      <section class="card camera-card">
        <div class="card-head-inline">
          <h2 class="h3"><?= icon('camera') ?> Camera scanner</h2>
          <button type="button" id="camera-toggle" class="btn btn-primary"><?= icon('camera') ?> <span>Start camera</span></button>
        </div>
        <div id="reader" class="reader" hidden></div>
        <p id="camera-status" class="hint">Uses the phone or laptop camera. Needs HTTPS or localhost. If the camera is blocked, use the ticket code box.</p>
      </section>

      <section class="card scan-card">
        <h2 class="h3">Recent scans on this device</h2>
        <ol id="scan-log" class="scan-log">
          <li class="scan-empty muted small">No scans yet.</li>
        </ol>
      </section>
    </div>
  </div>
</div>
<script type="application/json" id="checkin-config"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
<?php render_footer(['area' => 'staff', 'scripts' => ['js/checkin.js']]); ?>
