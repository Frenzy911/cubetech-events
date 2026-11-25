<?php
require_once __DIR__ . '/config.php';

$code = normalize_ticket_code(get_str('code', 40));
$contact = '';
$error = null;
$errorTitle = null;

if (is_post()) {
    csrf_verify();
    $code = normalize_ticket_code(post_str('code', 40));
    $contact = post_str('contact', 190);

    // Rate limit: max 10 failed lookups per 10 minutes per IP address.
    if (rate_limited('lookup', client_ip(), 10, 600)) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } elseif ($code === '' || $contact === '') {
        $error = 'Enter your ticket code and the email or mobile number you registered with.';
    } else {
        $reg = db_one('SELECT ticket_code, email, mobile FROM registrations WHERE ticket_code = ?', [$code]);
        $match = false;
        if ($reg) {
            $emailMatch = hash_equals(strtolower($reg['email']), strtolower($contact));
            $mobileMatch = hash_equals($reg['mobile'], normalize_mobile($contact));
            $match = $emailMatch || $mobileMatch;
        }
        if ($match) {
            redirect(ticket_path($reg['ticket_code']));
        }
        rate_hit('lookup', client_ip());
        // Same message whether the code or the email was wrong: reveals nothing.
        $errorTitle = 'Ticket not found.';
        $error = 'Check your ticket code and the email or mobile number you used to register.';
    }
}

render_header('Find my ticket', ['narrow' => true, 'active' => 'lookup']);
?>
<section class="card">
  <h1 class="h2"><?= icon('search') ?> Find my ticket</h1>
  <p class="muted">Enter the ticket code from your ticket page or email, plus the email address or mobile number you registered with.</p>

  <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><?= icon('x') ?><div><?php if ($errorTitle): ?><strong><?= h($errorTitle) ?></strong> <?php endif; ?><?= h($error) ?></div></div>
  <?php endif; ?>

  <form method="post" class="form" novalidate data-validate>
    <?= csrf_field() ?>
    <?= field('code', 'Ticket code', $code, null, [
        'required' => true, 'data-rule' => 'required', 'autocomplete' => 'off', 'autocapitalize' => 'characters',
        'spellcheck' => 'false', 'placeholder' => 'CDM-7K3P-9QXW-M2RT', 'maxlength' => 40, 'class' => 'input-code',
    ]) ?>
    <?= field('contact', 'Email or mobile number', $contact, null, [
        'required' => true, 'data-rule' => 'required', 'autocomplete' => 'email', 'autocapitalize' => 'off',
        'spellcheck' => 'false', 'placeholder' => 'juan@example.com or 09171234567', 'maxlength' => 190,
    ]) ?>
    <button class="btn btn-primary btn-lg btn-block" type="submit"><?= icon('ticket') ?> Show my ticket</button>
  </form>
</section>
<?php render_footer(); ?>
