<?php
require_once __DIR__ . '/config.php';

$eventId = (int) ($_GET['event'] ?? $_POST['event_id'] ?? 0);
$e = get_event($eventId);
if (!$e || $e['status'] !== 'published') {
    render_error_page(404, 'Event not found', 'This event does not exist or is not open for registration.');
}

$values = ['full_name' => '', 'email' => '', 'mobile' => ''];
$errors = [];
$formError = null;
$duplicate = false;

if (is_post()) {
    csrf_verify();
    $values = [
        'full_name' => preg_replace('/\s+/u', ' ', post_str('full_name', 200)),
        'email'     => strtolower(post_str('email', 254)),
        'mobile'    => normalize_mobile(post_str('mobile', 30)),
    ];
    $intent = post_str('intent') === 'waitlist' ? 'waitlist' : 'register';

    $errors = validate_registration($values);
    if (!$errors) {
        $result = register_attendee($eventId, $values, $intent);
        if ($result['ok']) {
            send_ticket_email($result['id'], $result['status'] === 'waitlisted' ? 'waitlisted' : 'registered');
            flash('success', $result['status'] === 'waitlisted'
                ? "You're on the waitlist. Keep this page so you can check your position."
                : "You're registered! Show this QR code at the entrance. Bookmark this page or take a screenshot.");
            redirect(ticket_path($result['code']));
        }
        $formError = $result['error'];
        $duplicate = $result['reason'] === 'duplicate';
        $e = get_event($eventId); // refresh spot counts
    } else {
        $formError = 'Please fix the ' . (count($errors) === 1 ? 'error' : count($errors) . ' errors') . ' below.';
    }
}

$ended = event_is_ended($e);
$full = event_is_full($e);
$isWaitlist = $full;

render_header($isWaitlist ? 'Join the waitlist' : 'Register', ['narrow' => true, 'active' => 'events']);
?>
<a class="back-link" href="<?= h(url('event.php?id=' . (int) $e['id'])) ?>"><?= icon('arrow-left') ?> Back to event</a>

<section class="card">
  <p class="eyebrow"><?= $isWaitlist ? 'Join the waitlist' : 'Register' ?></p>
  <h1 class="h2"><?= h($e['title']) ?></h1>
  <p class="meta"><?= icon('calendar') ?><span><?= h(fmt_date($e['starts_at'])) ?> · <?= h(event_time_range($e)) ?></span></p>
  <p class="meta"><?= icon('pin') ?><span><?= h($e['venue']) ?></span></p>

  <?php if ($ended): ?>
    <div class="alert alert-info" role="status"><?= icon('info') ?><div>This event has ended, so registration is closed.</div></div>
  <?php else: ?>

    <?php if ($formError): ?>
      <div class="alert alert-error" role="alert" id="form-error">
        <?= icon('x') ?>
        <div>
          <?= h($formError) ?>
          <?php if ($duplicate): ?>
            <br><a href="<?= h(url('lookup.php')) ?>">Find your ticket</a> using your ticket code and this email.
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($isWaitlist): ?>
      <div class="alert alert-warning" role="note">
        <?= icon('hourglass') ?>
        <div><strong>This event is full.</strong> Join the waitlist and you'll get a confirmed QR ticket if a spot opens and the organizer moves you up. Waitlist spots are first come, first served.</div>
      </div>
    <?php else: ?>
      <p class="spots"><?= icon('users') ?><span><?= event_spots_left($e) ?> of <?= (int) $e['capacity'] ?> spots left</span></p>
    <?php endif; ?>

    <form method="post" action="<?= h(url('register.php?event=' . (int) $e['id'])) ?>" class="form" novalidate data-validate>
      <?= csrf_field() ?>
      <input type="hidden" name="event_id" value="<?= (int) $e['id'] ?>">
      <input type="hidden" name="intent" value="<?= $isWaitlist ? 'waitlist' : 'register' ?>">

      <?= field('full_name', 'Full name', $values['full_name'], $errors['full_name'] ?? null, [
          'autocomplete' => 'name', 'maxlength' => 120, 'required' => true, 'data-rule' => 'name',
          'placeholder' => 'Juan Dela Cruz',
      ]) ?>
      <?= field('email', 'Email address', $values['email'], $errors['email'] ?? null, [
          'type' => 'email', 'autocomplete' => 'email', 'inputmode' => 'email', 'maxlength' => 190, 'required' => true,
          'data-rule' => 'email', 'placeholder' => 'juan@example.com', 'autocapitalize' => 'off', 'spellcheck' => 'false',
      ], 'We use this to find your ticket again.') ?>
      <?= field('mobile', 'Mobile number', $values['mobile'], $errors['mobile'] ?? null, [
          'type' => 'tel', 'autocomplete' => 'tel', 'inputmode' => 'numeric', 'maxlength' => 16, 'required' => true,
          'data-rule' => 'mobile', 'placeholder' => '09171234567',
      ], '11 digits starting with 09, for example 09171234567.') ?>

      <button class="btn btn-primary btn-lg btn-block" type="submit">
        <?= $isWaitlist ? icon('hourglass') . ' Join the waitlist' : icon('ticket') . ' Register and get my ticket' ?>
      </button>
      <p class="muted small center">Free event. No payment needed.</p>
    </form>
  <?php endif; ?>
</section>
<?php render_footer(); ?>
