<?php
/** Every email the app sends is stored here, so you can test emails without a mail server. */
require_once __DIR__ . '/../config.php';
$staff = require_organizer();

$id = (int) ($_GET['id'] ?? 0);
$statusTone = ['sent' => 'green', 'failed' => 'red', 'logged' => 'gray'];
$statusText = ['sent' => 'Sent via SMTP', 'failed' => 'SMTP failed', 'logged' => 'Saved locally'];

if ($id) {
    $mail = db_one('SELECT * FROM email_outbox WHERE id = ?', [$id]);
    if (!$mail) {
        render_error_page(404, 'Email not found', 'This email does not exist.', 'staff');
    }
    render_header('Email · ' . $mail['subject'], ['area' => 'staff', 'active' => 'outbox']);
    ?>
    <a class="back-link" href="<?= h(url('staff/outbox.php')) ?>"><?= icon('arrow-left') ?> Email outbox</a>
    <section class="card">
      <h1 class="h2"><?= h($mail['subject']) ?></h1>
      <p class="muted">To <strong><?= h($mail['to_email']) ?></strong> · <?= h(fmt_datetime($mail['created_at'])) ?> · <?= badge($statusText[$mail['status']], $statusTone[$mail['status']]) ?></p>
      <?php if ($mail['error']): ?>
        <div class="alert alert-error" role="alert"><?= icon('x') ?><div><?= h($mail['error']) ?></div></div>
      <?php endif; ?>
      <iframe class="email-preview" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" title="Email preview" srcdoc="<?= h($mail['body_html']) ?>"></iframe>
    </section>
    <?php
    render_footer(['area' => 'staff']);
    exit;
}

$mails = db_all('SELECT id, to_email, subject, status, created_at FROM email_outbox ORDER BY id DESC LIMIT 100');

render_header('Email outbox', ['area' => 'staff', 'active' => 'outbox']);
?>
<div class="page-head">
  <div>
    <h1>Email outbox</h1>
    <p class="muted">
      <?php if (mail_configured()): ?>
        SMTP is configured (<?= h(env('MAIL_HOST')) ?>): emails are sent and also saved here.
      <?php else: ?>
        SMTP is not configured, so emails are saved here only. To send them to a sandbox inbox such as Mailtrap, fill in the <code>MAIL_*</code> settings in <code>.env</code>.
      <?php endif; ?>
    </p>
  </div>
</div>

<section class="card card-flush">
  <?php if (!$mails): ?>
    <p class="muted pad">No emails yet. Register for an event to see the confirmation email here.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table table-stack">
      <thead><tr><th scope="col">When</th><th scope="col">To</th><th scope="col">Subject</th><th scope="col">Status</th></tr></thead>
      <tbody>
        <?php foreach ($mails as $m): ?>
          <tr>
            <td class="nowrap small" data-label="When"><?= h(fmt_datetime($m['created_at'])) ?></td>
            <td class="span-all" data-label="To"><?= h($m['to_email']) ?></td>
            <td class="span-all" data-label="Subject"><a href="<?= h(url('staff/outbox.php?id=' . (int) $m['id'])) ?>"><?= h($m['subject']) ?></a></td>
            <td data-label="Status"><?= badge($statusText[$m['status']], $statusTone[$m['status']]) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php render_footer(['area' => 'staff']); ?>
