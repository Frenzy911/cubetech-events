<?php
require_once __DIR__ . '/config.php';

// A ticket page needs the ticket code AND its signature (from registering, the email, or Find my ticket).
$code = normalize_ticket_code((string) ($_GET['code'] ?? $_POST['code'] ?? ''));
$sig = (string) ($_GET['sig'] ?? $_POST['sig'] ?? '');
$reg = ticket_sig_valid($code, $sig) ? get_registration_by_code($code) : null;

if (!$reg) {
    http_response_code(404);
    render_header('Ticket not found', ['narrow' => true]);
    ?>
    <section class="card empty-state">
      <div class="empty-icon"><?= icon('search') ?></div>
      <h1>Ticket not found</h1>
      <p class="muted">This ticket link is not valid. Use your ticket code and email to find your ticket.</p>
      <p><a class="btn btn-primary" href="<?= h(url('lookup.php')) ?>">Find my ticket</a></p>
    </section>
    <?php
    render_footer();
    exit;
}

$ended = strtotime($reg['ends_at']) <= time();

// Attendee cancels their own registration
if (is_post() && post_str('action') === 'cancel') {
    csrf_verify();
    if ($ended) {
        flash('error', 'This event has ended, so the registration can no longer be cancelled.');
    } else {
        $result = cancel_registration($reg, null);
        $result['ok']
            ? flash('success', 'Your registration has been cancelled. Your spot is now free for someone else.')
            : flash('error', $result['error']);
    }
    redirect(ticket_path($reg['ticket_code']));
}

$status = $reg['status'];
$hasQr = in_array($status, ['confirmed', 'checked_in'], true);
$waitPos = $status === 'waitlisted' ? waitlist_position($reg) : null;

render_header('Your ticket', ['narrow' => true, 'body_class' => 'page-ticket']);
?>
<article class="ticket ticket-<?= h($status) ?>" aria-labelledby="ticket-title">
  <header class="ticket-head">
    <p class="ticket-eyebrow"><?= icon('ticket') ?> <?= h(APP_NAME) ?> · Ticket</p>
    <h1 id="ticket-title"><?= h($reg['event_title']) ?></h1>
    <div class="badges"><?= status_badge($status, $waitPos) ?><?= $ended ? badge('Event ended', 'gray', 'clock') : '' ?></div>
  </header>

  <div class="ticket-main">
    <?php if ($hasQr): ?>
      <div class="ticket-qr">
        <?= QrCode::svg($reg['ticket_code']) ?>
      </div>
      <p class="ticket-code" aria-label="Ticket code"><?= h($reg['ticket_code']) ?></p>
      <?php if ($status === 'checked_in'): ?>
        <p class="ticket-state ticket-state-green"><?= icon('check') ?> Checked in at <?= h(fmt_datetime($reg['checked_in_at'])) ?></p>
      <?php else: ?>
        <p class="ticket-hint">Show this QR code at the entrance.</p>
      <?php endif; ?>
    <?php elseif ($status === 'waitlisted'): ?>
      <div class="ticket-notice ticket-notice-yellow">
        <?= icon('hourglass', 'icon icon-xl') ?>
        <p class="ticket-notice-title">You are #<?= (int) $waitPos ?> on the waitlist</p>
        <p>This is <strong>not a ticket yet</strong>. If a spot opens and the organizer confirms you, this page will show your QR code. Check back here using your ticket code.</p>
      </div>
      <p class="ticket-code" aria-label="Reference code"><?= h($reg['ticket_code']) ?></p>
    <?php else: ?>
      <div class="ticket-notice ticket-notice-gray">
        <?= icon('x', 'icon icon-xl') ?>
        <p class="ticket-notice-title">This registration was cancelled</p>
        <p>Cancelled on <?= h(fmt_datetime($reg['cancelled_at'])) ?>. This code can no longer be used to enter.</p>
      </div>
      <p class="ticket-code ticket-code-void" aria-label="Ticket code"><?= h($reg['ticket_code']) ?></p>
    <?php endif; ?>
  </div>

  <dl class="ticket-details">
    <div><dt>Attendee</dt><dd><?= h($reg['full_name']) ?></dd></div>
    <div><dt>Date</dt><dd><?= h(fmt_date($reg['starts_at'])) ?></dd></div>
    <div><dt>Time</dt><dd><?= h(event_time_range($reg)) ?> <span class="muted">(Cebu time)</span></dd></div>
    <div><dt>Venue</dt><dd><?= h($reg['venue']) ?></dd></div>
    <div><dt>Email</dt><dd><?= h(mask_email($reg['email'])) ?></dd></div>
    <div><dt>Registered</dt><dd><?= h(fmt_datetime($reg['registered_at'])) ?></dd></div>
  </dl>
</article>

<div class="ticket-actions no-print">
  <?php if ($hasQr): ?>
    <button type="button" class="btn btn-secondary" data-print><?= icon('printer') ?> Print / save as PDF</button>
  <?php endif; ?>
  <a class="btn btn-ghost" href="<?= h(url('lookup.php')) ?>"><?= icon('search') ?> Find another ticket</a>
</div>
<p class="muted small center no-print">Tip: bookmark this page or take a screenshot so you can open it at the door.</p>

<?php if (in_array($status, ['confirmed', 'waitlisted'], true) && !$ended): ?>
  <details class="card cancel-box no-print">
    <summary>Can't make it? Cancel your registration</summary>
    <p class="muted">Cancelling frees your spot for someone on the waitlist. This can't be undone, but you can register again later if spots are available.</p>
    <form method="post" action="<?= h(url('ticket.php')) ?>" data-confirm="Cancel your registration for <?= h($reg['event_title']) ?>? This can't be undone.">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cancel">
      <input type="hidden" name="code" value="<?= h($reg['ticket_code']) ?>">
      <input type="hidden" name="sig" value="<?= h($sig) ?>">
      <button type="submit" class="btn btn-danger"><?= icon('x') ?> Cancel my registration</button>
    </form>
  </details>
<?php endif; ?>
<?php render_footer(); ?>
